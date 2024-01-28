<?php

/**
 *
 * Copyright (C) 2023 Highend Technologies LLC
 * This file is part of Zignaly OpenTradeApi.
 *
 * Zignaly OpenTradeApi is free software: you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * Zignaly OpenTradeApi is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Zignaly OpenTradeApi.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use Zignaly\redis\ZignalyLastPriceRedisService;
use Zignaly\redis\ZignalyLastTradesRedisService;
use Zignaly\utils\PositionUtils;

class ExitPosition
{
    /** @var Monolog $Monolog */
    private $Monolog;
    /** @var ZignalyLastPriceRedisService $lastPriceService */
    private $lastPriceService;
    /** @var ZignalyLastTradesRedisService $lastTradesProvider */
    private $lastTradesProvider;
    /** @var RedisLockController $RedisLockController */
    private $RedisLockController;
    /** @var ExchangeCalls $ExchangeCalls */
    private $ExchangeCalls;
    /** @var newPositionCCXT $newPositionCCXT */
    private $newPositionCCXT;
    /** @var Accounting $Accounting */
    private $Accounting;
    /** @var Status $Status */
    private $Status;
    /** @var RedisHandler $RedisHandlerZignalyQueue */
    private $RedisHandlerZignalyQueue;
    /** @var newUser $newUser */
    private $newUser;
    private $processName;
    private $queueName;
    private $requeue;
    private $positionId;

    public function __construct(
        Monolog & $Monolog,
        string $processName,
        string $queueName
    ) {
        $container = DIContainer::getContainer();
        $this->Monolog = $Monolog;
        $this->lastPriceService = $container->get('lastPrice');
        $this->lastTradesProvider = $container->get('recentHistoryPrices');
        $this->RedisLockController = $container->get('RedisLockController');
        $this->ExchangeCalls = $container->get('exchangeMediator');
        $this->newPositionCCXT = $container->get('newPositionCCXT.model');
        $this->newPositionCCXT->configureLoggingByContainer($container);
        $this->newPositionCCXT->configureExchangeCalls($this->ExchangeCalls);
        $this->newPositionCCXT->configureLastPriceService($this->lastPriceService);
        $this->newPositionCCXT->initiateAccounting();
        $this->Accounting = $container->get('accounting');
        $this->Status = $container->get('position.status');
        $this->RedisHandlerZignalyQueue = $container->get('redis.queue');
        $this->newUser = $container->get('newUser.model');
        $this->processName = $processName;
        $this->queueName = $queueName;
    }

    public function process(string $positionId, int $status, bool $requeue = false) : array
    {
        $this->requeue = $requeue;
        $this->positionId = $positionId;
        try {
            $position = $this->getPositionForExit($status);
            if (!$position) {
                return [470, 'No position found'];
            }

            if (!empty($position->closed)) {
                $this->Monolog->sendEntry('debug', 'Position already closed.');
                return [470, 'Position already closed'];
            }

            if ($this->checkIfStopLossOrderAlreadyPlaced($position)) {
                $this->RedisLockController->removeLock($this->positionId, $this->processName, 'all');
                return [470, 'There is already an exit order placed for this position.'];
            }

            $positionMediator = PositionMediator::fromMongoPosition($position);

            if (!$this->ExchangeCalls->setCurrentExchange($positionMediator->getExchange()->getId(),
                $positionMediator->getExchangeType())) {
                $this->Monolog->sendEntry('critical', 'Error connecting the exchange');
                $this->RedisLockController->removeLock($this->positionId, $this->processName, 'all');
                $this->reSendMessageToQueue($status);
                return [470, 'Position locked by a different process, it will retry the exit later.'];
            }

            if ($this->newPositionCCXT->cancelPendingOrders($position)) {
                $position = $this->newPositionCCXT->getPosition($position->_id);
                $symbol = $positionMediator->getSymbol();
                list(, $remainingAmount) = $this->Accounting->recalculateAndUpdateAmounts($position);
                $amountToSell = 0;
                $isAmountGood = false;
                $isCostGood = false;

                if ($remainingAmount > 0) {
                    $amountToSell = $this->ExchangeCalls->getAmountToPrecision($remainingAmount, $symbol);
                    $isAmountGood = $this->ExchangeCalls->checkIfValueIsGood('amount', 'min', $amountToSell, $symbol);
                    $exchangeMediator = $positionMediator->getExchangeMediator();
                    $exchangeHandler = $exchangeMediator->getExchangeHandler();
                    $lastPrice = $positionMediator->getLastPrice();
                    $posSize = $exchangeHandler->calculatePositionSize($symbol, $amountToSell, $lastPrice);
                    $remainingPositionSize = number_format($posSize, 12, '.', '');
                    $isCostGood = $this->ExchangeCalls->checkIfValueIsGood('cost', 'min', $remainingPositionSize, $symbol);
                }

                if (!$position->closed) {
                    if (54 === $status) {
                        $set = [
                            'closed' => true,
                            'status' => 54,
                            'closedAt' => new UTCDateTime(),
                        ];
                        $this->newPositionCCXT->setPosition($position->_id, $set);
                        return [200, 'Position manually canceled.'];
                    } elseif ($isAmountGood && $isCostGood) {
                        $orderType = 'MARKET';
                        $orderSide = $positionMediator->isShort() ? 'buy' : 'sell';

                        $options = PositionUtils::extractOptionsForOrder($positionMediator);

                        $amountToReduce = $this->ExchangeCalls->getAmountToPrecision($amountToSell, $symbol);
                        $amounts = $positionMediator->getExchangeMediator()->getExchangeHandler()->getMaxAmountsForMarketOrders(
                            $amountToReduce,
                            $symbol,
                            $this->Monolog
                        );

                        foreach ($amounts as $amount) {
                            $order = $this->ExchangeCalls->sendOrder(
                                $position->user->_id,
                                $position->exchange->internalId,
                                $positionMediator->getSymbol(),
                                $orderType,
                                $orderSide,
                                $amount,
                                false,
                                $options,
                                true,
                                $position->_id->__toString()
                            );

                            if (is_object($order)) {
                                $this->newPositionCCXT->updatePositionFromSellSignal($position, $order, $status, $orderType, $amount);
                            } else {
                                $statusFromError = $this->Status->getPositionStatusFromError($order['error']);
                                $tryExit = isset($position->tryExit) ? $position->tryExit + 1 : 1;
                                $retryStatuses = [24, 32, 38, 79, 84, 87,];
                                if ($tryExit > 4 || !in_array($statusFromError, $retryStatuses)) {
                                    $this->newPositionCCXT->updatePositionFromSellSignalError($position, $order, $orderType, $this->ExchangeCalls);
                                    return [470, 'Error placing exit order after 4 tries.'];
                                } else {
                                    $log = [];
                                    $log[] = [
                                        'date' => new UTCDateTime(),
                                        'message' => "Trying to exit the position ($tryExit), status: $statusFromError",
                                    ];
                                    $this->Monolog->sendEntry('warning', "API keys fail, we'll retry again.");
                                    $pushLogs = empty($log) ? false : ['logs' => ['$each' => $log]];

                                    $setPosition = [
                                        'sellingByStopLoss' => false,
                                        'tryExit' => $tryExit,
                                    ];
                                    $this->newPositionCCXT->setPosition($position->_id, $setPosition, true, $pushLogs);
                                    $this->RedisLockController->removeLock($this->positionId, $this->processName, 'all');
                                    $this->reSendMessageToQueue($status);
                                    return [470, 'Error placing the exit order, it will be retried later.'];
                                }
                            }
                        }

                        if (is_object($order)) {
                            if ('closed' === $order->getStatus()) {
                                $position = $this->newPositionCCXT->getPosition($position->_id);
                                $CheckOrdersCCXT = new CheckOrdersCCXT($position, $this->ExchangeCalls, $this->newPositionCCXT, $this->Monolog);
                                $CheckOrdersCCXT->checkOrders(true);
                            }
                        }
                    } else {
                        $tradesSideType = isset($position->side) && strtolower($position->side) == 'short' ? 'buy' : 'sell';
                        $realSellPrice = number_format($this->Accounting->getAveragePrice($position, $tradesSideType), 12, '.', '');
                        $setPosition = [
                            'closed' => true,
                            'closedAt' => new UTCDateTime(),
                            'status' => $this->getClosingStatus($position),
                            'sellPerformed' => true,
                            'realSellPrice' => (float)($realSellPrice),
                        ];

                        $this->newPositionCCXT->setPosition($position->_id, $setPosition);
                        return [200, 'Position closed but still some remaining amount below minimum for exiting.'];
                    }
                } else {
                    $this->Monolog->sendEntry('debug', "Position already closed");
                    return [470, 'Position already closed'];
                }
            } else {
                $this->Monolog->sendEntry('error', "Couldn't cancel orders from the position");
                if ($this->checkIfConnectedExchangeExists($position)) {
                    $this->RedisLockController->removeLock($this->positionId, $this->processName, 'all');
                    $this->reSendMessageToQueue($status);
                    return [470, 'Could not cancel pending orders, will retry later.'];
                } else {
                    $this->Monolog->sendEntry('warning', "Closing because the connected exchange is not active anymore.");
                    $setPosition = [
                        'closed' => true,
                        'closedAt' => new UTCDateTime(),
                        'status' => 90,
                    ];
                    $this->newPositionCCXT->setPosition($position->_id, $setPosition);
                    return [470, 'Closing because the connected exchange is not active anymore.'];
                }
            }

            $this->RedisLockController->removeLock($this->positionId, $this->processName, 'all');
            $position = $this->newPositionCCXT->getPosition($position->_id);
            if ($position->closed) {
                $this->RedisHandlerZignalyQueue->addSortedSet('accountingQueue', 0, $position->_id->__toString());
                return [200, 'Exit order sent and filled'];
            } else {
                return [200, 'Exit order sent'];
            }
        } catch (Exception $e) {
            $this->Monolog->sendEntry('critical', "Failed: Message: " . $e->getMessage());
            if (!empty($position)) {
                $this->RedisLockController->removeLock($positionId, $this->processName, 'all');
            }
        }
    }

    /**
     * @param int $status
     * @return BSONDocument|bool
     */
    private function getPositionForExit(int $status)
    {
        $position = $this->newPositionCCXT->getPosition($this->positionId);

        if (!empty($position->user)) {
            $this->Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
            $this->Monolog->addExtendedKeys('positionId', $position->_id->__toString());
        }

        if (!empty($position->closed)) {
            return $position;
        }

        if (!$this->RedisLockController->positionHardLock(
            $position->_id->__toString(),
            $this->processName,
            600,
            true)) {
            $this->reSendMessageToQueue($status);
            return false;
        }

        return $position;
    }

    /**
     * @param string $status
     * @return void
     */
    private function reSendMessageToQueue(string $status): void
    {
        if (!$this->requeue) {
            return;
        }

        $message = json_encode(
            [
                'positionId' => $this->positionId,
                'status' => $status,
            ],
            JSON_PRESERVE_ZERO_FRACTION
        );
        $this->RedisHandlerZignalyQueue->addSortedSet($this->queueName, time(), $message, true);
    }

    /**
     * @param BSONDocument $position
     * @param Monolog $Monolog
     * @return bool
     */
    private function checkIfStopLossOrderAlreadyPlaced(BSONDocument $position): bool
    {
        if (16 === $position->status && !empty($position->orders)) {
            foreach ($position->orders as $order) {
                $lastOrder = $order;
            }

            if ('stopLoss' === $lastOrder->type && !$lastOrder->done) {
                $this->Monolog->sendEntry('debug', "StopLoss already placed.");
                return true;
            }
        }

        return false;
    }

    /**
     * @param BSONDocument $position
     * @return bool
     */
    function checkIfConnectedExchangeExists(BSONDocument $position): bool
    {
        $user = $this->newUser->getUser($position->user->_id);
        if (empty($user->exchanges)) {
            return false;
        }

        foreach ($user->exchanges as $exchange) {
            if (!empty($exchange->internalId) && $exchange->internalId == $position->exchange->internalId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param BSONDocument $position
     * @return int
     */
    function getClosingStatus(BSONDocument $position): int
    {
        $status = 72;

        if (empty($position->orders)) {
            return $status;
        }

        $type = "none";

        foreach ($position->orders as $order) {
            if ($order->type != 'buy' && $order->type != 'entry' && $order->status == 'closed' && $order->done) {
                $type = $order->type;
                if (isset($order->positionStatus)) {
                    $status = $order->positionStatus;
                }
            }
        }

        if ($type == 'takeProfit') {
            $status = 17;
        }

        return $status;
    }
}