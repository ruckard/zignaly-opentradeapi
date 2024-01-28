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


use MongoDB\Model\BSONDocument;
use Zignaly\exchange\BaseExchange;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use Zignaly\utils\PositionUtils;

require_once __DIR__ . '/loader.php';
global $RabbitMQ;

$container = DIContainer::getContainer();
$processName = 'stopLossWorker';
$queueName = 'stopLoss';//(isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : 'stopLoss';

$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
$lastPriceService = $container->get('lastPrice');
$lastTradesProvider = $container->get('recentHistoryPrices');
/** @var RedisLockController $RedisLockController */
$RedisLockController = $container->get('RedisLockController');


$ExchangeCalls = new ExchangeCalls($Monolog);
$newPositionCCXT->configureLogging($Monolog);
$newPositionCCXT->configureExchangeCalls($ExchangeCalls);
$newPositionCCXT->configureLastPriceService($lastPriceService);
$newPositionCCXT->initiateAccounting();

$count = 0;

$callback = function ($msg) use ($lastPriceService, $lastTradesProvider) {
    require_once dirname(__FILE__) . '/loader.php';
    global $Accounting, $ExchangeCalls, $Monolog, $newPositionCCXT, $processName, $Status,
           $continueLoop, $container, $RabbitMQ, $queueName, $RedisLockController, $count;


    if (!$continueLoop && $count > 0) {
        exit();
    }
    $count++;

    if (null == $msg) {
        return;
    }
    
    $Monolog->trackSequence();
    $RedisHandlerZignalyQueue = $container->get('redis.queue');

    try {
        $message = json_decode($msg->body, true);
        $Monolog->sendEntry('info', ": Received ", $message);

        $positionId = $message['positionId'];
        if (empty($positionId)) {
            $Monolog->sendEntry('debug', "No position id.");
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
            return;
        }

        $Monolog->addExtendedKeys('positionId', $positionId);
        $position = $RedisLockController->positionHardLock($positionId, $processName);
        //$position = $newPositionCCXT->getAndLockPosition($positionId, $processName, false, false, false);

        if (!$position) {
            $position = $newPositionCCXT->getPosition($positionId);
            if (empty($position)) {
                $Monolog->sendEntry('ERROR', "Position doesn't exists", $message);
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag'], false, true);
                return;
            } elseif ($position->closed || checkIfStopLossOrderAlreadyPlaced($position, $Monolog)) {
                //$Monolog->sendEntry('debug', "Trying to process with stop loss a closed or selling position");
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
                return;
            } else {
                $lockedBy = empty($position->lockedBy) ? '' : $position->lockedBy;
                $lockedAt = empty($position->lockedAt) ? '' : $position->lockedAt;
                $lockedFrom = empty($position->lockedFrom) ? '' : $position->lockedFrom;
                $Monolog->sendEntry(
                    'debug',
                    sprintf("Locked by %s from %s at %s", $lockedBy, $lockedFrom, $lockedAt)
                );
                if ($lockedBy != $processName) {
                    $RabbitMQ->publishMsg($queueName, $msg->body);
                }

                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag'], false, true);

                return;
            }
        }

        if ($position && isset($position->status)) {
            $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
        }

        if (checkIfStopLossOrderAlreadyPlaced($position, $Monolog)) {
            $Monolog->sendEntry('debug', "StopLoss already placed.");
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag'], false, true);
            return;
        }

        if ($position && !$position->closed && empty($position->sellingByStopLoss)) {
            $positionMediator = PositionMediator::fromMongoPosition($position);
            $exchangeAccountType = $positionMediator->getExchangeType();
            $isTestnet = $positionMediator->getExchangeIsTestnet();

            if (!$ExchangeCalls->setCurrentExchange(
                $positionMediator->getExchange()->getId(),
                $exchangeAccountType,
                $isTestnet
            )) {
                $Monolog->sendEntry('critical', 'Error connecting the exchange');
                $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
                $RabbitMQ->publishMsg($queueName, $msg->body);
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag'], false, true);

                return;
            }

            if ($newPositionCCXT->cancelPendingOrders($position)) {
                $position = $newPositionCCXT->getPosition($position->_id);
                // LFERN $symbol = $position->signal->base . $position->signal->quote;
                $symbol = $positionMediator->getSymbol();
                list(, $remainingAmount) = $Accounting->recalculateAndUpdateAmounts($position);
                $lastPrice = 0;
                $amountToSell = 0;
                $isAmountGood = false;
                $isCostGood = false;

                if ($remainingAmount > 0) {
                    $amountToSell = $ExchangeCalls->getAmountToPrecision(
                        $remainingAmount,
                        // LFERN $position->signal->base.'/'.$position->signal->quote
                        $positionMediator->getSymbol()
                    );

                    $isAmountGood = $ExchangeCalls->checkIfValueIsGood(
                        'amount',
                        'min',
                        $amountToSell,
                        $symbol
                    );
                    $exchangeMediator = $positionMediator->getExchangeMediator();
                    $exchangeHandler = $exchangeMediator->getExchangeHandler();
                    $lastPrice = $positionMediator->getLastPrice();
                    $posSize = $exchangeHandler->calculatePositionSize(
                        $symbol,
                        $amountToSell,
                        $lastPrice
                    );
                    
                    $remainingPositionSize = number_format(
                        // LFERN $amountToSell * $lastPrice,
                         $posSize,
                        12,
                        '.',
                        ''
                    );

                    $isCostGood = $ExchangeCalls->checkIfValueIsGood(
                        'cost',
                        'min',
                        $remainingPositionSize,
                        $symbol
                    );
                }

                /*$Monolog->sendEntry('info', "Price: $lastPrice Remaining Amount: $remainingAmount"
                    . ", Amount to Sell: $amountToSell, Remaining Position Size: $remainingPositionSize");*/

                if (!$position->closed) {
                    if ($message['status'] == 54) {
                        //$Monolog->sendEntry('warning', "Closing position because it was canceled");
                        $set = [
                            'closed' => true,
                            'status' => 54,
                            'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                        ];
                        $newPositionCCXT->setPosition($position->_id, $set);
                    } elseif ($isAmountGood && $isCostGood) {
                        //$Monolog->sendEntry('debug', "Position remaining amount looks good");
                        $limitPrice = isset($message['limitPrice']) && $message['limitPrice'];
                        $price = $lastPrice;
                        if (isset($message['limitPrice']) && $message['limitPrice']) {
                            $price = $message['limitPrice'];
                        } elseif (isset($message['price']) && $message['price']) {
                            $price = $message['price'];
                        }

                        $priceDeviation = $limitPrice || !isset($position->exchange->sellPriceDeviation)
                        || !$position->exchange->sellPriceDeviation || $position->exchange->sellPriceDeviation == 0
                            ? 1 : $position->exchange->sellPriceDeviation;

                        $priceLimit = $ExchangeCalls->getPriceToPrecision(
                            $price * $priceDeviation,
                            //LFERN $position->signal->base.'/'.$position->signal->quote
                            $positionMediator->getSymbol()
                        );

                        $orderType = isset($message['orderType']) ? strtoupper($message['orderType']) : 'MARKET';
                        $isInternalExchangeId = isset($position->exchange->internalId);
                        $exchangeId = isset($position->exchange->internalId) ? $position->exchange->internalId
                            : $position->exchange->_id->__toString();
                        $orderSide = $positionMediator->isShort() ? 'buy' : 'sell';

                        $options = PositionUtils::extractOptionsForOrder($positionMediator);

                        $positionUserId = empty($position->profitSharingData) ? $position->user->_id : $position->profitSharingData->exchangeData->userId;
                        $positionExchangeInternalId = empty($position->profitSharingData) ? $exchangeId : $position->profitSharingData->exchangeData->internalId;

                        $amountToReduce = $ExchangeCalls->getAmountToPrecision($amountToSell, $positionMediator->getSymbol());
                        $amounts = $positionMediator->getExchangeMediator()->getExchangeHandler()->getMaxAmountsForMarketOrders(
                            $amountToReduce,
                            $positionMediator->getSymbol(),
                            $Monolog
                        );

                        foreach ($amounts as $amount) {
                            $order = $ExchangeCalls->sendOrder(
                                $positionUserId,
                                $positionExchangeInternalId,
                                // LFERN $position->signal->base.'/'.$position->signal->quote,
                                $positionMediator->getSymbol(),
                                $orderType,
                                $orderSide,
                                $amount,
                                $priceLimit,
                                $options,
                                $isInternalExchangeId,
                                $position->_id->__toString()
                            );

                            if (is_object($order)) {
                                $newPositionCCXT->updatePositionFromSellSignal($position, $order, $message['status'], $orderType, $amount);
                            } else {
                                $statusFromError = $Status->getPositionStatusFromError($order['error']);
                                $tryExit = isset($position->tryExit) ? $position->tryExit + 1 : 1;
                                $retryStatuses = [24, 32, 38, 79, 84, 87, ];
                                if ($tryExit > 4 || !in_array($statusFromError, $retryStatuses)) {
                                    $newPositionCCXT->updatePositionFromSellSignalError($position, $order, $orderType, $ExchangeCalls);
                                } else {
                                    $log = [];
                                    $log[] = [
                                        'date' => new \MongoDB\BSON\UTCDateTime(),
                                        'message' => "Trying to exit the position ($tryExit), status: $statusFromError",
                                    ];
                                    $Monolog->sendEntry('warning', "API keys fail, we'll retry again.");
                                    $pushLogs = empty($log) ? false : ['logs' => ['$each' => $log]];

                                    $setPosition = [
                                        'sellingByStopLoss' => false,
                                        'tryExit' => $tryExit,
                                    ];
                                    $newPositionCCXT->setPosition($position->_id, $setPosition, true, $pushLogs);
                                    $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
                                    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag'], false, true);
                                    return;
                                }
                            }
                        }
                        
                        if (is_object($order)) {
                            if ('closed' === $order->getStatus()) {
                                $position = $newPositionCCXT->getPosition($position->_id);
                                $CheckOrdersCCXT = new CheckOrdersCCXT($position, $ExchangeCalls, $newPositionCCXT, $Monolog);
                                $CheckOrdersCCXT->checkOrders(true);
                            } /*elseif ('market' === $order->getType()) {
                                $quickPriceWatcherQueueName = empty($position->testNet) && empty($position->paperTrading) ? 'quickPriceWatcher' : 'quickPriceWatcher_Demo';
                                $RedisHandlerZignalyQueue->addSortedSet($quickPriceWatcherQueueName, time(), $positionId);
                            }*/
                        }
                    } else {
                        //$Monolog->sendEntry('warning', "Closing because amount or position size is below minimum allowed");
                        $tradesSideType = isset($position->side) && strtolower($position->side) == 'short' ? 'buy' : 'sell';
                        $realSellPrice = number_format($Accounting->getAveragePrice($position, $tradesSideType), 12, '.', '');
                        $setPosition = [
                            'closed' => true,
                            'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                            'status' => getClosingStatus($position, $Monolog),
                            'sellPerformed' => true,
                            'realSellPrice' => (float)($realSellPrice),
                        ];

                        $newPositionCCXT->setPosition($position->_id, $setPosition);
                    }
                } else {
                    $Monolog->sendEntry('debug', "Position already closed");
                }
            } else {
                $Monolog->sendEntry('error', "Couldn't cancel position");
                $newUser = $container->get('newUser.model');
                if (checkIfConnectedExchangeExists($position, $newUser)) {
                    $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
                    $RabbitMQ->publishMsg($queueName, $msg->body);
                    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag'], false, true);
                    return;
                } else {
                    $Monolog->sendEntry('warning', "Closing because the connected exchange is not active anymore.");
                    $setPosition = [
                        'closed' => true,
                        'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                        'status' => 90,
                    ];
                    $newPositionCCXT->setPosition($position->_id, $setPosition);
                }
            }
        }
        if ($position && isset($position->status)) {
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
            $position = $newPositionCCXT->getPosition($position->_id);
            if ($position->closed) {
                $RedisHandlerZignalyQueue->addSortedSet('accountingQueue', 0, $positionId);
            }
        }
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Failed: Message: " . $e->getMessage());
        if (!empty($position) && isset($position->_id)) {
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
        }
    }

    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
};

if (isset($argv['1']) && isset($argv['2'])) {
    $continueLoop = false;
    $callback((object) [
        'body' => json_encode([
            'positionId' => $argv['1'],
            'status' => $argv['2']
        ])
    ]);
} else {
    $RabbitMQ->consumeMsg($queueName, $callback);
}

/**
 * Check if stop loss order has already been placed.
 *
 * @param BSONDocument $position
 * @param Monolog $Monolog
 * @return bool
 */
function checkIfStopLossOrderAlreadyPlaced(BSONDocument $position, Monolog $Monolog)
{
    if ($position->status == 16 && !empty($position->orders)) {
        foreach ($position->orders as $order) {
            $lastOrder = $order;
        }

        if (empty($lastOrder)) {
            return false;
        }

        if ($lastOrder->type == 'stopLoss' && !$lastOrder->done) {
            return true;
        }
    }

    return false;
}

/**
 * Check if the connected exchange to the position is active under the user exchanges.
 *
 * @param \MongoDB\Model\BSONDocument $position
 * @param newUser $newUser
 * @return bool
 */
function checkIfConnectedExchangeExists(\MongoDB\Model\BSONDocument $position, newUser $newUser)
{
    if (empty($position->exchange->internalId)) {
        return false;
    }

    $user = $newUser->getUser($position->user->_id);
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
 * Get the proper status for closing the position.
 *
 * @param \MongoDB\Model\BSONDocument $position
 * @param Monolog $Monolog
 * @return int
 */
function getClosingStatus(\MongoDB\Model\BSONDocument $position, Monolog $Monolog)
{
    $status = 72;
    if (empty($position->orders)) {
        return $status;
    }

    $type = "none";

    foreach ($position->orders as $order) {
        //$Monolog->sendEntry('debug', "Id: {$order->orderId} Type: {$order->type}, Status: {$order->status} Done: {$order->done}");
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
