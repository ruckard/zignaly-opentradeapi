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
use MongoDB\Driver\Cursor;
use MongoDB\Operation\FindOneAndUpdate;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;
use Symfony\Component\DependencyInjection\Container;
use Zignaly\Balance\BalanceService;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\exchange\ExchangeOrder;
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\ExchangeHandler\ExchangeHandler;
use Zignaly\Mediator\ExchangeMediator\ExchangeMediator;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use Zignaly\service\ZignalyLastPriceService;

class newPositionCCXT
{
    /** @var Accounting $Accounting */
    private $Accounting;
    private $collectionName = 'position';
    private $collectionNameClosed = 'position_closed';
    /** @var \MongoDB\Database  */
    private $mongoDBLink;
    /** @var \MongoDB\Database  */
    private $mongoDBLinkRO;
    /** @var Monolog  */
    private $Monolog;
    /** @var PositionCacheGenerator */
    private $PositionCacheGenerator = false;
    // private $RedisHandler;
    /** @var ZignalyLastPriceService */
    private $lastPriceService;
    private $Security;
    /** @var ExchangeCalls */
    private $ExchangeCalls;
    /** @var RedisTriggersWatcher */
    private $RedisTriggersWatcher;

    public function __construct()
    {
        global $mongoDBLink, $Security;

        $container = DIContainer::getContainer();

        $container->set('monolog', new Monolog('newPositionCCXT'));
        $this->Monolog = $container->get('monolog');

        $this->mongoDBLink = $mongoDBLink;
        $this->Security = $Security;
        $this->RedisTriggersWatcher = $container->get('RedisTriggersWatcher');
    }

    public function initiateAccounting()
    {
        $container = DIContainer::getContainer();
        $this->Accounting = $container->get('accounting');
    }

    public function configurePositionCacheGenerator()
    {
        $container = DIContainer::getContainer();

        if (!$this->PositionCacheGenerator) {
            $this->PositionCacheGenerator = $container->get('PositionCacheGenerator');
        }
    }

    public function configureMongoDBLinkRO()
    {
        global $mongoDBLinkRO;

        $this->mongoDBLinkRO = $mongoDBLinkRO;
    }

    public function configureLogging(Monolog $Monolog)
    {
        $this->Monolog = $Monolog;
    }

    /**
     * Configure the logging container.
     *
     * @param Container $container
     */
    public function configureLoggingByContainer(Container $container)
    {
        try {
            $this->Monolog = $container->get('monolog');
        } catch (Exception $e) {
            echo time() . " " . $e->getMessage() . "\n";
        }
    }

    public function configureExchangeCalls(ExchangeCalls &$ExchangeCalls)
    {
        $this->ExchangeCalls = $ExchangeCalls;
    }

    /**
     * Undocumented function
     *
     * @param ZignalyLastPriceService $lastPriceService
     * @return void
     */
    public function configureLastPriceService(ZignalyLastPriceService &$lastPriceService)
    {
        $this->lastPriceService = $lastPriceService;
    }

    /**
     * Return the number of positions for a given providerId.
     *
     * @param ObjectId $providerId
     * @param bool $closed
     * @param bool|ObjectId $userId
     * @return int
     */
    public function countPositions(ObjectId $providerId, bool $closed, $userId)
    {
        $find = [
            'signal.providerId' => $providerId,
        ];

        if ($closed) {
            $find['accounting.closingDate'] = [
                '$exists' => true,
            ];
        } else {
            $find['closed'] = false;
        }

        if ($userId) {
            $find['user._id'] = $userId;
        }

        return $this->mongoDBLink->selectCollection($this->collectionName)->count($find);
    }

    public function calculateProfitFromCopyTradingClosedPositions($userId, $providerId, $fromDate)
    {
        $pipeline = [
            [
                '$match' => [
                    'closed' => true,
                    'user._id' => $userId,
                    'provider._id' => $providerId,
                    'accounting.done' => true,
                    'accounting.closingDate' => [
                        '$gte' => $fromDate,
                    ]
                ]
            ],
            [
                '$group' => [
                    '_id' => '$provider._id',
                    'profit' => [
                        '$sum' => '$accounting.netProfit'
                    ]
                ]
            ]
        ];

        $results = $this->mongoDBLink->selectCollection($this->collectionName)->aggregate($pipeline);

        foreach ($results as $result) {
            if ($result->_id == $providerId) {
                if (empty($result->profit)) {
                    $profit = 0;
                } else {
                    $profit = is_object($result->profit) ? $result->profit->__toString() : $result->profit;
                }
                $fundingFees = 0;
                return (float)($profit + $fundingFees);
            }
        }

        return 0;
    }

    /**
     * Cancels the given order and update the position with the order data.
     *
     * @param string $orderId
     * @param BSONDocument $position
     * @return bool
     */
    public function cancelOrder(string $orderId, BSONDocument $position)
    {
        global $Accounting;

        $this->Monolog->sendEntry('debug', "Canceling order $orderId");
        $order = $this->ExchangeCalls->exchangeCancelOrder($orderId, $position);

        if (!is_object($order)) {
            $this->Monolog->sendEntry('debug', "Cancel order $orderId failed: " . $order['error']);
            return false;
        }

        list($targets, $targetId) = $this->getTargetsAndTargetIdFromOrderId($position, $orderId);
        if ($order->getFilled() > 0) {
            $trades = $this->ExchangeCalls->getTrades($position, false, $order);
            if (!empty($trades)) {
                $position = $this->pushDataAndReturnDocument($position->_id, 'trades', $trades);
                $position = $this->reAdjustTradesIfMultiPosition($position, $orderId);
            } else {
                $this->Monolog->sendEntry("critical", "No trades found from partially filled order " . $order->getId());
            }
            $positionMediator = PositionMediator::fromMongoPosition($position);
            $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
            $orderPrice = number_format($order->getPrice(), 12, '.', '');
            $tradesSideType = isset($position->side) && strtolower($position->side) == 'short' ? 'sell' : 'buy';
            $avgPrice = $Accounting->getAveragePrice($position, $tradesSideType);
            list($totalAmount, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($position);
            $orderAmount = $Accounting->getRealAmount($position, $orderId);
            $realPositionSize = $exchangeHandler->calculatePositionSize($positionMediator->getSymbol(), $totalAmount, $avgPrice);
            $leverage = empty($position->leverage) ? 1 : $position->leverage;
            $realInvestment = number_format($realPositionSize / $leverage, 12, '.', '');
            $setPosition = [
                "orders.$orderId.price" => $orderPrice,
                "orders.$orderId.amount" => $orderAmount,
                "orders.$orderId.cost" => $exchangeHandler->calculateOrderCostZignalyPair($positionMediator->getSymbol(), $orderAmount, $orderPrice),
                "realAmount" => (float)$totalAmount,
                "remainAmount" => (float)$remainAmount,
                "realPositionSize" => (float)$realPositionSize,
                "realBuyPrice" => (float)$avgPrice,
                "avgBuyingPrice" => (float)$avgPrice,
                'realInvestment' => (float)$realInvestment,
            ];


            if ($targetId) {
                if ('takeProfitTargets' === $targets) {
                    $setPosition["$targets.$targetId.orderId"] = false;
                    $setPosition["$targets.$targetId.updated"] = new UTCDateTime();
                    $setPosition["$targets.$targetId.note"] = "Partially filled from order $orderId";
                    $this->Monolog->sendEntry('critical', 'Take profit partially filled'); //Todo: remove after we have checked that it worked fine.
                } else {
                    if ($order->getFilled() < $order->getAmount()) {
                        if ($targets == 'reBuyTargets') {
                            $amountField = 'quantity';
                        } elseif ($targets == 'reduceOrders') {
                            $amountField = 'availablePercentage';
                        } else {
                            $amountField = false;
                        }

                        $setPosition["$targets.$targetId.$amountField"] = $this->extractFilledTargetFactor($order, $position->$targets->$targetId);
                    }
                    $setPosition["$targets.$targetId.done"] = true;
                    $setPosition["$targets.$targetId.updated"] = new UTCDateTime();
                }
            }
        }

        $setPosition["orders.$orderId.status"] = $order->getStatus();
        if (!empty($targets) && !empty($targetId)) {
            if (('canceled' === $order->getStatus() || 'cancelled' === $order->getStatus()) && 0 == $order->getFilled()) {
                $setPosition["$targets.$targetId.orderId"] = false;
            } elseif (ExchangeOrderStatus::Expired === $order->getStatus()) {
                $setPosition = array_merge($setPosition, $this->manageExpiredOrder($position, $order));
            }
        }
        $setPosition["orders.$orderId.done"] = true;

        $this->setPosition($position->_id, $setPosition);
        $this->updateNewOrderField($position->_id, $setPosition, $orderId);

        return true;
    }

    /**
     * Check if there is remaining amount and all take profits are done and the total to sell from them was 100%
     * @param Accounting $Accounting
     * @param BSONDocument $position
     * @return bool
     */
    public function checkIfTakeProfitWasTheLastOneAndThereIsRemainingAmount(Accounting $Accounting, BSONDocument $position)
    {
        //If position is already closed there is no need to look further.
        if ($position->closed) {
            return false;
        }

        //If the remaining amount is 0, there is no need to look further.
        list(, $remainingAmount) = $Accounting->recalculateAndUpdateAmounts($position);
        if (empty($remainingAmount)) {
            return false;
        }

        //We check the amount percentage from all take profits done.
        $amountSold = 0;
        foreach ($position->takeProfitTargets as $target) {
            if (!empty($target->done) && !empty($target->orderId) && !empty($target->filledAt)) {
                $amountSold += $target->amountPercentage;
            }
        }

        //If amount sold is 100% (1), then we should send a stop loss right now.
        if ($amountSold >= 1) {
            return true;
        }

        //It looks like there is still some take profits pending.
        return false;
    }

    /**
     * Check if it's needed to flip the position and return parameters.
     * @param string $orderId
     * @return array
     */
    public function flipPosition(BSONDocument $position, string $orderId)
    {
        if ('MULTI' !== $position->buyType) {
            return [];
        }

        if ('buy' === $position->orders->$orderId->side) {
            return [];
        }

        $setPosition['side'] = 'SHORT';

        if (!empty($position->takeProfitTargets)) {
            foreach ($position->takeProfitTargets as $target) {
                if (empty($target->done) && $target->priceTargetPercentage > 1) {
                    $targetId = $target->targetId;
                    $setPosition["takeProfitTargets.$targetId.priceTargetPercentage"] = 2 - $target->priceTargetPercentage;
                }
            }
        }

        if (!empty($position->reduceOrders)) {
            foreach ($position->reduceOrders as $target) {
                if (empty($target->done)) {
                    $targetId = $target->targetId;
                    $setPosition["reduceOrders.$targetId.targetPercentage"] = 2 - $target->targetPercentage;
                }
            }
        }

        if (!empty($position->reBuyTargets)) {
            foreach ($position->reBuyTargets as $target) {
                if (empty($target->done) && $target->triggerPercentage < 1) {
                    $targetId = $target->targetId;
                    $setPosition["reBuyTargets.$targetId.triggerPercentage"] = 2 - $target->triggerPercentage;
                }
            }
        }

        if (!empty($position->stopLossPercentage) && $position->stopLossPercentage < 1) {
            $setPosition["stopLossPercentage"] = 2 - $position->stopLossPercentage;
        }

        if (!empty($position->trailingStopPercentage) && $position->trailingStopPercentage < 1) {
            $setPosition["trailingStopPercentage"] = 2 - $position->trailingStopPercentage;
        }

        if (!empty($position->trailingStopDistancePercentage) && $position->trailingStopDistancePercentage < 1) {
            $setPosition["trailingStopDistancePercentage"] = 2 - $position->trailingStopDistancePercentage;
        }

        if (!empty($position->trailingStopTriggerPercentage) && $position->trailingStopTriggerPercentage > 1) {
            $setPosition["trailingStopTriggerPercentage"] = 2 - $position->trailingStopTriggerPercentage;
        }

        return $setPosition;
    }

    /**
     * Adjust position if it's MULTI and a fake trade was introduced to adjust the contract.
     * This is need for MULTI positions because a partial filled order from the other side could have reduce the contract.
     * @param BSONDocument $position
     * @param string $orderId
     * @return BSONDocument
     */
    private function reAdjustTradesIfMultiPosition(BSONDocument $position, string $orderId)
    {
        if ('MULTI' !== $position->buyType) {
            return $position;
        }

        if (empty($position->orders->$orderId->originalEntry)) {
            return $position;
        }

        foreach ($position->orders as $order) {
            if ($order->orderId === $orderId) {
                continue;
            }

            if (empty($order->originalEntry)) {
                continue;
            }

            $filledOriginalOrderId = $order->orderId;
        }

        if (empty($filledOriginalOrderId)) {
            return $position;
        }

        $orderAmount = 0;
        foreach ($position->trades as $trade) {
            if ($trade->orderId !== $orderId) {
                continue;
            }
            $orderAmount += ($trade->qty * 1);
        }

        if (empty($orderAmount)) {
            return $position;
        }

        foreach ($position->trades as $trade) {
            if ($trade->orderId !== $filledOriginalOrderId || empty($trade->fakeTradeForSyncingContract)) {
                continue;
            }
            $fixAmount = $trade->qty + $orderAmount;
            if ($fixAmount > 0) {
                $fixAmount = 0;
            }

            return $this->updateTradeField($position->_id, $trade->id, $trade->orderId, 'qty', $fixAmount);
        }

        return $position;
    }

    /**
     * Update the field of a trade for the given tradeId and orderId
     * @param ObjectId $positionId
     * @param string $tradeId
     * @param string $orderId
     * @param string $field
     * @param string $value
     * @return array|object|null|BSONDocument
     */
    private function updateTradeField(ObjectId $positionId, string $tradeId, string $orderId, string $field, string $value)
    {
        $find = [
            '_id' => $positionId,
            'trades' => [
                '$elemMatch' => [
                    'id' => $tradeId,
                    'orderId' => $orderId,
                ],
            ],
        ];

        $set = [
            '$set' => [
                'trades.$.'.$field => $value,
            ],
        ];

        $options = [
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $set, $options);
    }

    /**
     * Cancels pending orders from position for the given type.
     *
     * @param BSONDocument $position
     * @param array $type
     * @return bool
     */
    public function cancelPendingOrders(BSONDocument $position, array $type = [])
    {
        global $Accounting, $Exchange;

        $return = true;
        foreach ($position->orders as $order) {
            if (!$return) {
                return $return;
            }

            unset($orderId);
            if (!$order->done && ($type == false || in_array($order->type, $type))) {
                $this->Monolog->sendEntry("debug", "Canceling order {$order->orderId}");
                $canceledOrder = $this->ExchangeCalls->exchangeCancelOrder($order->orderId, $position);

                if (!is_object($canceledOrder) && isset($canceledOrder['error'])) {
                    $msgError = $canceledOrder['error'];
                    $method = $Exchange->getLogMethodFromError($msgError);
                    $this->Monolog->sendEntry($method, "Canceling order {$order->orderId} failed with error: " . $msgError);

                    if (
                        false !== stripos($msgError, 'Order does not exist')
                        || false !== stripos($msgError, 'Not valid order status from ccxt expired')
                    ) {
                        $orderId = $order->orderId;
                        $currentRetry = empty($position->orders->$orderId->cancelRetry) ? 0 : $position->orders->$orderId->cancelRetry;
                        $firstCancelTry = isset($order->firstCancelTry) ? $order->firstCancelTry : time();
                        if ($currentRetry < 5 || time() - $firstCancelTry < 3600) {
                            $this->Monolog->sendEntry('debug', "Order {$order->orderId} doesn't exist or is expired, but will retry again.");

                            $currentRetry++;
                            $setPosition = [
                                "orders.$orderId.cancelRetry" => $currentRetry,
                            ];
                            if (!isset($order->firstCancelTry)) {
                                $setPosition["orders.$orderId.firstCancelTry"] = time();
                            }
                            $return = false;
                        } else {
                            $this->Monolog->sendEntry('debug', "Order {$order->orderId} doesn't exist or is expired, so, everything is fine.");
                            $setPosition = [
                                "orders.$orderId.status" => 'canceled',
                                "orders.$orderId.done" => true,
                            ];
                            if ($order->type == 'buy' || $order->type == 'entry') {
                                $targetId = $this->getTargetIdByOrderId($position, $orderId, 'reBuyTargets');
                            }
                            if (isset($targetId) && $targetId) {
                                $setPosition["reBuyTargets.$targetId.cancel"] = true;
                            }
                        }
                    } elseif (stripos($msgError, 'Invalid API key/secret pair') !== false && isset($position->exchange->internalId)) {
                        global $newUser;

                        $user = $newUser->getUser($position->user->_id);
                        foreach ($user->exchanges as $tmpExchange) {
                            if ($tmpExchange->internalId == $position->exchange->internalId) {
                                $exchange = $tmpExchange;
                            }
                        }
                        if (isset($exchange) && !$exchange->areKeysValid) {
                            global $Notification;

                            $this->Monolog->sendEntry('debug', "Closing position because invalid keys");
                            $setPosition = [
                                'closed' => true,
                                'status' => 32,
                            ];
                            $return = false;
                            $domain = $user->projectId == 'ct01' ? 'app.altexample.com' : 'example.net';
                            $positionUrl = 'https://' . $domain . '/app/position/' . $position->_id->__toString();
                            $headMessage = "*ERROR:* Invalid key/secret pair \n";
                            $endingMessage = "The position [$positionUrl]($positionUrl) has been closed.";
                            $message = $headMessage . $endingMessage;
                            $Notification->sendPositionUpdateNotification($user, $message);
                        } else {
                            $this->Monolog->sendEntry("debug", "From here, the process should stop because there was an issue canceling the order.");
                            $return = false;
                        }
                    } else {
                        $return = false;
                    }

                    if (isset($setPosition)) {
                        $this->setPosition($position->_id, $setPosition, true);
                        if (!empty($orderId)) {
                            $this->updateNewOrderField($position->_id, $setPosition, $orderId);
                        }
                    }
                } else {
                    $this->Monolog->sendEntry("debug", "Canceling order {$order->orderId} for position OK");
                    $orderStatus = $canceledOrder->getStatus();
                    $orderId = $order->orderId;

                    if ($orderStatus != 'open') {
                        $setPosition = [
                            "orders.$orderId.status" => $orderStatus,
                            "orders.$orderId.done" => true,
                        ];

                        $this->Monolog->sendEntry("debug", "Order {$order->orderId} filled: " . $canceledOrder->getFilled());

                        if ($order->type == 'takeProfit') {
                            $targetId = $this->getTargetIdByOrderId($position, $orderId, 'takeProfitTargets');
                        } elseif ($order->type == 'buy' || $order->type == 'entry') {
                            $targetId = $this->getTargetIdByOrderId($position, $orderId, 'reBuyTargets');
                        }
                        if ($canceledOrder->getFilled() > 0) {
                            // LFERN $setPosition["orders.$orderId.cost"] = $canceledOrder->getPrice() * $canceledOrder->getFilled();
                            $positionMediator = PositionMediator::fromMongoPosition($position);
                            $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
                            $setPosition["orders.$orderId.cost"] = $exchangeHandler->calculateOrderCostZignalyPair(
                                $positionMediator->getSymbol(),
                                $canceledOrder->getFilled(),
                                $canceledOrder->getPrice()
                            );
                            $this->Monolog->sendEntry("debug", "Order {$order->orderId} partially filled");
                            $trades = $this->ExchangeCalls->getTrades($position, $canceledOrder->getId());
                            if (!empty($trades)) {
                                $this->Monolog->sendEntry("debug", "Trades from partially filled position:", $trades);
                                $position = $this->pushDataAndReturnDocument($position->_id, 'trades', $trades);
                            } else {
                                $this->Monolog->sendEntry("critical", "No trades found from partially filled order " . $canceledOrder->getId());
                            }
                            if (isset($targetId) && $targetId > 0 && $canceledOrder->getFilled() == $order->amount) {
                                if ($order->type == 'takeProfit') {
                                    $setPosition["takeProfitTargets.$targetId.done"] = true;
                                    $setPosition["takeProfitTargets.$targetId.filledAt"] =
                                        $this->getDateTimeFromLastTrade($position, $orderId);
                                } elseif ($order->type == 'buy' || $order->type == 'entry') {
                                    $setPosition["reBuyTargets.$targetId.done"] = true;
                                }
                            }
                        } else {
                            if (($order->type == 'buy'  || $order->type == 'entry') && isset($position->reBuyTargets) && $position->reBuyTargets
                                && isset($targetId) && $targetId > 0) {
                                $setPosition["reBuyTargets.$targetId.done"] = false;
                                $setPosition["reBuyTargets.$targetId.orderId"] = false;
                            }
                        }

                        //$this->Monolog->sendEntry("debug", "Settings:", $setPosition);
                        list(, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($position);

                        $setPosition['remainAmount'] = (float)$remainAmount;
                        $this->setPosition($position->_id, $setPosition, true);
                        $this->updateNewOrderField($position->_id, $setPosition, $orderId);
                    } else {
                        $this->Monolog->sendEntry('critical', ": Canceling order {$order->orderId} failed for position: "
                            . $position->_id->__toString() . ' current status "OPEN" -> ' . $orderStatus);
                        if ($canceledOrder instanceof ExchangeOrderCcxt) {
                            $cancelError = is_array($canceledOrder->getCcxtResponse()) ? $canceledOrder->getCcxtResponse() : [$canceledOrder->getCcxtResponse()];
                            $this->Monolog->sendEntry('info', "Canceled order value: ", $cancelError);
                        }
                        $return = false;
                    }
                }
            }
        }

        return $return;
    }

    public function countFilledTargets($targets, $orders)
    {
        if (!$targets) {
            return 0;
        }

        $counter = 0;

        foreach ($targets as $target) {
            if (isset($target->done) && $target->done && $target->orderId) {
                $orderId = $target->orderId;
                if (isset($orders->$orderId) && $orders->$orderId->cost > 0) {
                    $counter++;
                }
            }
        }

        return $counter;
    }

    public function findPositionFromOrderId($userId, $orderId)
    {
        $find = [
            'user._id' => $this->parseMongoDBObject($userId),
            'closed' => false,
            'orders.' . $orderId => [
                '$exists' => true,
            ],
            'orders.' . $orderId . '.done' => false
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);
    }

    /**
     * Extract the cost from an entry cost.
     *
     * @param object $position
     * @param object $order
     * @param int $leverage
     * @return float
     */
    private function extractCostFromEntryOrder($position, object $order, int $leverage)
    {
        if ($order->type != 'entry') {
            return 0.0;
        }

        if (
            (
                $order->status == 'canceled'
                || $order->status == 'cancelled'
                || $order->status == ExchangeOrderStatus::Expired
            )
            && $order->cost == 0
        ) {
            return 0.0;
        }

        if (empty($order->price) || empty($order->amount)) {
            return 0.0;
        }

        $price = is_object($order->price) ? $order->price->__toString() : $order->price;
        $amount = is_object($order->amount) ? $order->amount->__toString() : $order->amount;

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

        //return ($price * $amount) / $leverage;
        return $exchangeHandler->calculateOrderCostZignalyPair(
            $positionMediator->getSymbol(),
            $amount,
            $price
        ) / ($leverage > 0? $leverage : 1);
    }

    public function getOlderUnlockedPositionAndLockIt($sortField, $process, $status = false, $processFlag = false)
    {
        $find = [
            'locked' => false,
            'closed' => false,
        ];

        if ($status)
            $find['status'] = $status;

        if ($processFlag)
            $find[$processFlag] = true;

        $set = [
            '$set' => [
                'locked' => true,
                'lockedAt' => new UTCDateTime(),
                'lockedBy' => $process,
                'lockedFrom' => gethostname(),
            ]
        ];

        $options = [
            'sort' => [
                $sortField => 1,
            ],
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        $position = $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $set, $options);
        if (isset($position->lockedFrom) && $position->lockedFrom == gethostname()
            && isset($position->lockedBy) && $position->lockedBy == $process && !empty($position->locked)) {
            return $position;
        } else {
            return false;
        }
    }

    public function getAndLockPosition($positionId, $process, $flag = false, $debug = false, $fast = false)
    {
        $lockId = md5(uniqid(microtime(true) * rand(1,1000), true));

        $find = [
            '_id' => $this->parseMongoDBObject($positionId),
            'locked' => false,
        ];

        $set = [
            '$set' => [
                'locked' => true,
                'lockedAt' => new UTCDateTime(),
                'lockedBy' => $process,
                'lockedFrom' => gethostname(),
                'lockId' => $lockId
            ]
        ];

        if ($flag) {
            $set['$set'][$flag] = true;
        }

        $options = [
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        try {
            $try = $fast ? 60 : 1;
            do {
                $this->mongoDBLink->selectCollection($this->collectionName)->updateOne($find, $set, $options);
                //ATTENTION!!! If we are using a readPreference other than primary, the getPosition will be outdated almost for sure.
                $position = $this->getPosition($positionId);
                if (!empty($position->lockId) && $position->lockId == $lockId) {
                    return $position;
                } else {
                    if ($position) {
                        $this->checkIfPositionNeedsUnlock($position);
                    }
                    if (!$position || $position->closed) {
                        return false;
                    }
                    //$this->Monolog->sendEntry('error', "Try $try, locked by ".$position->lockedBy);
                    sleep(1);
                }
                $try++;
            } while ($try < 60);

            return false;
        } catch (Exception $e) {
            $this->Monolog->sendEntry('error', "Failed locking position: "
                . $this->parseMongoDBObject($positionId)->__toString() . " with error: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Check if the position needs to be unlocked and unlocks it if so.
     *
     * @param BSONDocument $position
     */
    public function checkIfPositionNeedsUnlock(BSONDocument $position)
    {
        $lockedAt = $position->lockedAt->__toString() / 1000;
        $tenMinAgo = time() - 90;
        if ($tenMinAgo > $lockedAt) {
            $lockedFrom = isset($position->lockedFrom) ? $position->lockedFrom : 'Unknown';
            $this->Monolog->sendEntry('debug', "Unlock  because it has been locked more than 10m. Time: "
                . time() . " Locked at: $lockedAt, 90 sec ago: $tenMinAgo. By " . $position->lockedBy . ", from $lockedFrom");
            $this->unlockPosition($position->_id);
        }
    }

    /**
     * Get positions that have been updating for more than 5 minutes.
     *
     * @param int $minutes
     * @return Cursor
     */
    public function getBlockedPositions(int $minutes)
    {
        $timeLimit = (time() - $minutes * 60) * 1000;
        $find = [
            'closed' => false,
            'updating' => true,
            'lastUpdatingAt' => [
                '$lt' => new UTCDateTime($timeLimit),
            ],
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find);
    }

    /**
     * Set the parameter unsuccessfulLockCounter for a given position.
     *
     * @param ObjectId $positionId
     * @param string $processName
     * @param int $counter
     * @return array|object|null
     */
    public function increaseUnsuccessfulLockCounter(ObjectId $positionId, string $processName, int $counter)
    {
        $find = [
            '_id' => $positionId,
        ];

        $set = [
            '$set' => [
                $processName . 'UnsuccessfulLockCounter' => $counter,
            ],
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $set);
    }


    /**
     * Return the date from the first closed position with sell performed or false if any.
     *
     * @param ObjectId $userId
     * @return bool|object
     */
    public function getClosingDateFromFirstClosedPosition(ObjectId $userId)
    {
        $find = [
            'user._id' => $userId,
            'closed' => true,
            'sellPerformed' => true,
        ];

        $options = [
            'sort' => [
                'closedAt' => 1
            ],
            'limit' => 1,
        ];

        $positions = $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);

        foreach ($positions as $position) {
            if (isset($position->closedAt))
                return $position->closedAt;
        }

        return false;
    }

    /**
     * Get current open positions for checking if they are in Redis.
     *
     * @param bool $limitProjection
     * @param bool|string $userId
     * @return Cursor
     */
    public function getOpenPositionsForRedis($limitProjection = true, $userId = false)
    {
        $find = [
            'closed' => false,
            'status' => [
                '$in' => [
                    1,
                    9
                ]
            ]
        ];

        if ($userId) {
            $find['user._id'] = $this->parseMongoDBObject($userId);
        }

        if ($limitProjection) {
        $options = [
            'projection' => [
                '_id' => 1,
                'orders' => 1,
                // new fields needed for bitmex
                'exchange.name' => true,
                'exchange.exchangeType' => true,
                'exchange.isTestnet' => true,
                'exchange.internalId' => true,
                'signal.pair' => true               
            ]
        ];
        } else {
            $options = [];
        }

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Return the last or  first position, real or not from a given user.
     *
     * @param ObjectId $userId
     * @param bool $onlyReal
     * @param int $order
     * @param bool $sold
     * @return Cursor
     */
    public function getPositionDataForUser(ObjectId $userId, bool $onlyReal, int $order, bool $sold)
    {
        $find = [
            'user._id' => $userId,
            'buyPerformed' => true,
        ];

        if ($onlyReal) {
            $find['paperTrading'] = false;
            $find['testNet'] = false;
        }

        if ($sold) {
            $find['accounting.done'] = true;
        }

        if ($sold) {
            $options['sort'] = [
                'accounting.closingDate' => $order
            ];
        } else {
            $options['sort'] = [
                '_id' => $order
            ];
        }
        $options['limit'] = 1;


        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Return the number of buys or sells
     *
     * @param ObjectId $userId
     * @param string $type
     * @param bool $realOnly
     * @return int
     */
    public function getPositionBuysSellsForUser(ObjectId $userId, string $type, bool $realOnly)
    {
        if ($type == 'buy') {
            $find = [
                'user._id' => $userId,
                'buyPerformed' => true,
            ];
        } else {
            $find = [
                'closed' => true,
                'user._id' => $userId,
                'accounting.closingDate' => [
                    '$lte' => new UTCDateTime(),
                ],
            ];
        }

        if ($realOnly) {
            $find['paperTrading'] = false;
            $find['testNet'] = false;
        }

        return $this->mongoDBLink->selectCollection($this->collectionName)->count($find);
    }

    /**
     * Get the current open positions for a given symbol
     *
     * @param string $base
     * @param string $quote
     * @return Cursor
     */
    public function getOpenPositionsBySymbol(string $base, string $quote)
    {
        $find = [
            'closed' => false,
            'signal.base' => $base,
            'signal.quote' => $quote
        ];

        $options = [
            'projection' => [
                '_id' => true,
                'exchange.name' => true,
                'exchange.exchangeType' => true,
                // new fields needed for bitmex
                'exchange.isTestnet' => true,
                'exchange.internalId' => true,
                'signal.pair' => true,
                'paperTrading' => true,
                'testNet' => true
            ]
        ];
        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Return the open poisitons for the user from a given exchange account
     * @param ObjectId $userId
     * @param string $internalExchangeId
     * @return Cursor
     */
    public function getOpenPositionsFromUserInternalExchangeId(ObjectId $userId, string $internalExchangeId)
    {
        $find = [
            'closed' => false,
            'user._id' => $userId,
            'exchange.internalId' => $internalExchangeId,
        ];

        $options = [
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Return accounted positions for the given interval of time.
     * @param UTCDateTime $from
     * @param UTCDateTime $to
     * @return Cursor
     */
    public function getSoldPositionsFromTo(UTCDateTime $from, UTCDateTime $to)
    {
        $find = [
            'closed' => true,
            'sellPerformed' => true,
            'accounted' => true,
            'paperTrading' => false,
            'testNet' => false,
            'accounting.closingDate' => [
                '$gte' => $from,
                '$lte' => $to
            ]
        ];

        $options = [
            //'noCursorTimeout' => true,
            'projection' => [
                'exchange.name' => 1,
                'user._id' => 1,
                'accounting' => 1,
                'signal.quote' => 1,
                // new fields needed for bitmex
                'exchange.exchangeType' => true,
                'exchange.isTestnet' => true,
                'exchange.internalId' => true,
                'signal.pair' => true                
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Return the list of closed positions where the accounting exists, os they have been sold.
     *
     * @param ObjectId $userId
     * @param int $limit
     * @param string $internalExchangeId
     * @return Cursor
     */
    public function getAllSoldPositions(ObjectId $userId, int $limit, $internalExchangeId)
    {
        $find = [
            'user._id' => $userId,
            'exchange.internalId' => $internalExchangeId,
            'closed' => true,
            'accounting.done' => true,
        ];

        $options = [
            'limit' => $limit
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Return a list of ids from the open positions form the given id list.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @return array
     */
    public function getPositionsIdFromGivenList(string $userId, string $internalExchangeId)
    {
        $find = [
            'closed' => false,
            'user._id' => new ObjectId($userId),
            'exchange.internalId' => $internalExchangeId,
        ];

        $options = [
            'projection' => [
                '_id' => true,
            ]
        ];

        $positions = $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);

        $returnIds = [];
        foreach ($positions as $position) {
            $returnIds[] = $position->_id->__toString();
        }

        return $returnIds;
    }

    /**
     * @param object|string $positionId
     * @return BSONDocument|object|null
     */
    public function getPosition($positionId)
    {
        $positionId = $this->parseMongoDBObject($positionId);

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOne(['_id' => $positionId]);
    }

    public function getPositionForQuickPriceWatcher()
    {
        $find = [
            'locked' => false,
            'closed' => false,
        ];

        $update = [
            '$set' => [
                'lastCheckingOpenOrdersAt' => new UTCDateTime(),
            ]
        ];

        $options = [
            'projection' => [
                '_id' => 1,
            ],
            'sort' => [
                'lastCheckingOpenOrdersAt' => 1,

            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $update, $options);
    }

    /**
     * Find a position by the given userId and orderId.
     * @param string $orderId
     * @param string $zignalyId
     * @return array|object|null
     */
    public function getPositionByUserAndOrderId(string $orderId, string $zignalyId)
    {
        $find = [
            'order.orderId' => $orderId,
            'signal.pair' => $zignalyId,
        ];

        $options = [
            'projection' => [
                '_id' => 1,
                "orders.$orderId.done" => 1
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find, $options);
    }

    /**
     * Look for open positions with pending initial entry and mark them for canceling such entry.
     * @param array $signal
     * @return bool
     */
    public function markPositionsForCancelingEntry(array $signal) : bool
    {
        $find = [
            'signal.signalId' => $signal['signalId'],
            'signal.key' => $signal['key'],
            'signal.base' => $signal['base'],
            'signal.quote' => $signal['quote'],
            'buyPerformed' => false,
            'closed' => false,
        ];
        $update = [
            '$set' => [
                'manualCancel' => true,
                'updating' => true,
                'lastUpdatingAt' => new UTCDateTime(),
                'checkExtraParametersAt' => new UTCDateTime(),
            ],
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->updateMany($find, $update)->getModifiedCount() > 0;
    }

    /**
     * Insert the given array into the position collectio and return the id.
     *
     * @param array $position
     * @return string
     */
    public function insertAndGetId(array $position) : string
    {
        return $this->mongoDBLink->selectCollection($this->collectionName)->insertOne($position)->getInsertedId()->__toString();
    }

    /**
     * Check if the position has what it takes to send an entry order... or two.
     *
     * @param ExchangeCalls $ExchangeCalls
     * @param Status $Status
     * @param BSONDocument $exchange
     * @param array $position
     * @return void
     */
    public function checkIfPositionIsGoodForEntryOrder(ExchangeCalls  $ExchangeCalls, Status $Status, BSONDocument $exchange, array $position) : void
    {
        if (empty($position['exchange']['areKeysValid'])) {
            $message = $Status->getPositionStatusText(32);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if ($this->checkForDuplicateSignalId($position)) {
            $status = 47;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if (!$this->checkIfOpenFuturesPositionAreGood($position)) {
            $message = $Status->getPositionStatusText(83);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if ($this->checkIfSymbolOnList($exchange, $position, 'globalBlacklist', 'black')) {
            $status = 61;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if ($this->checkIfSymbolOnList($exchange, $position, 'globalWhitelist', 'white')) {
            $status = 63;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if ($this->checkIfCoinIsDelisted($exchange, $position)) {
            $status = 64;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        $price = $position['limitPrice'];
        $amount = $position['amount'];
        $cost = $position['positionSize'];
        $symbol = $position['signal']['pair'];
        if ('MARKET' !== $position['buyType']
            && !$ExchangeCalls->checkIfValueIsGood('price', 'min', $price, $symbol)) {
            $status = 5;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if (!$ExchangeCalls->checkIfValueIsGood('amount', 'min', $amount, $symbol)) {
            $status = 12;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if (!$ExchangeCalls->checkIfValueIsGood('amount', 'max', $amount, $symbol)) {
            $status = 100;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if (!$ExchangeCalls->checkIfValueIsGood('cost', 'min', $cost, $symbol)) {
            $status = 12;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if (time() - $position['signal']['datetime']->__toString() / 1000 > $position['buyTTL']) {
            $status = 3;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if (!$this->checkStopLossPriceForSelling($position, $ExchangeCalls)) {
            $status = 20;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if (!$this->checkTakeProfitsAmounts($position, $ExchangeCalls)) {
            $status = 39;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if ('STOP-LIMIT' === $position['buyType'] && empty($position['buyStopPrice'])) {
            $status = 42;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if (!$this->countCurrentOpenPositions($exchange, $position, 'globalMaxPositions')) {
            $status = 58;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        if (!$this->countCurrentOpenPositions($exchange, $position, 'globalPositionsPerMarket')) {
            $status = 60;
            $message = $Status->getPositionStatusText($status);
            $this->Monolog->sendEntry('info', $message);
            $this->sendHTTPCodeAndExit($message, 467);
        }

        $this->Monolog->sendEntry('info', "Everything OK for sending entry order.");
    }

    /**
     * Check if there is max open positions parameter set and check on db.
     *
     * @param BSONDocument $exchange
     * @param array $position
     * @param string $parameter
     * @return bool
     */
    private function countCurrentOpenPositions(BSONDocument $exchange, array $position, string $parameter) : bool
    {
        //Parameter: globalPositionsPerMarket or globalMaxPositions
        if (empty($exchange->$parameter)) {
            return true;
        }

        $maxPositions = $exchange->$parameter;

        $find = [
            'user._id' => $position['user']['_id'],
            'exchange.internalId' => $exchange->internalId,
            'closed' => false,
        ];

        if ('globalPositionsPerMarket' === $parameter) {
            $find['signal.pair'] = $position['signal']['pair'];
        }

        return $this->mongoDBLink->selectCollection($this->collectionName)->count($find) < $maxPositions;
    }

    /**
     * Check if there is any open position with the same signalId.
     *
     * @param array $position
     * @return bool
     */
    private function checkForDuplicateSignalId(array $position) : bool
    {
        if (empty($position['signal']['signalId'])) {
            return false;
        }

        $find = [
            'user._id' => $position['user']['_id'],
            'exchange.internalId' => $position['exchange']['internalId'],
            'signal.signalId' => $position['signal']['signalId'],
            'closed' => false,
        ];

        return $this->mongoDBLinkRO->selectCollection($this->collectionName)->count($find) > 0;
    }

    /**
     * Check if the values from take profits target are good.
     *
     * @param array $position
     * @param ExchangeCalls $ExchangeCalls
     * @return bool
     */
    private function checkTakeProfitsAmounts(array $position, ExchangeCalls $ExchangeCalls) : bool
    {
        if (!$position['takeProfitTargets']) {
            return true;
        }

        foreach ($position['takeProfitTargets'] as $target) {
            $sellingPrice = !empty($target['pricePriority']) && 'price' === $target['pricePriority'] && !empty($target['priceTarget'])
                ? $target['priceTarget'] : $position['limitPrice'] * $target['priceTargetPercentage'];
            $amountToSell = $position['amount'] * $target['amountPercentage'];
            $cost = $sellingPrice * $amountToSell;

            if (!$this->checkMinValues($ExchangeCalls, $amountToSell, $sellingPrice, $cost, $position['signal']['pair'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the order for the stop loss would be fine.
     *
     * @param array $position
     * @param ExchangeCalls $ExchangeCalls
     * @return bool
     */
    private function checkStopLossPriceForSelling(array $position, ExchangeCalls $ExchangeCalls) : bool
    {
        if (!$position['stopLossPercentage']) {
            return true;
        }

        $amountToSell = $position['amount'];
        $sellingPrice = $position['limitPrice'] * $position['stopLossPercentage'];
        $cost = $amountToSell * $sellingPrice;

        return $this->checkMinValues($ExchangeCalls, $amountToSell, $sellingPrice, $cost, $position['signal']['pair']);
    }

    /**
     * Check that the given values are good.
     *
     * @param ExchangeCalls $ExchangeCalls
     * @param float $amount
     * @param float $price
     * @param float $cost
     * @param string $symbol
     * @return bool
     */
    private function checkMinValues(ExchangeCalls $ExchangeCalls, float $amount, float $price, float $cost, string $symbol) : bool
    {
        if (!$ExchangeCalls->checkIfValueIsGood('price', 'min', $price, $symbol)) {
            return false;
        }

        if (!$ExchangeCalls->checkIfValueIsGood('amount', 'min', $amount, $symbol)) {
            return false;
        }

        if (!$ExchangeCalls->checkIfValueIsGood('cost', 'min', $cost, $symbol)) {
            return false;
        }

        return true;
    }

    /**
     * Check if any coin from the pair is delisted or marked for delisting.
     *
     * @param BSONDocument $exchange
     * @param array $position
     * @return bool
     */
    private function checkIfCoinIsDelisted(BSONDocument $exchange, array $position) : bool
    {
        if (empty($exchange->globalDelisting)) {
            return false;
        }

        $GlobalBlackList = new GlobalBlackList();

        return $GlobalBlackList->checkIfCoinsAreListed('Binance', $position['signal']['quote'], $position['signal']['base']);
    }


    /**
     * Check if the given symbol is the blacklist or whitelist.
     *
     * @param BSONDocument $exchange
     * @param array $position
     * @param $list
     * @param $listType
     * @return bool
     */
    private function checkIfSymbolOnList(BSONDocument $exchange, array $position, $list, $listType) : bool
    {
        if (empty($exchange->list)) {
            return false;
        }

        foreach ($position['exchange'][$list] as $listSymbol) {
            if ($position['signal']['pair'] == strtoupper($listSymbol)) {
                return 'black' === $listType;
            }
        }

        return !('black' === $listType);
    }

    /**
     * Check if the position has open contract and if the trader allows to have multi positions with the same contract.
     *
     * @param array $position
     * @return bool
     */
    private function checkIfOpenFuturesPositionAreGood(array $position) : bool
    {
        if ('futures' !== $position['exchange']['exchangeType']) {
            return true;
        }

        if (!empty($position['signal']['ignoreOpenContractCheck'])) {
            return true;
        }

        if(!empty($position['signal']['reverseId'])) {
            //If we get an entry signal with reverseId it means that a market sell order has been already sent so we can
            // ignore the following checks.
            return true;
        }

        $find = [
            'closed' => false,
            'user._id' => $position['user']['_id'],
            'signal.pair' => $position['signal']['pair'],
            'exchange.internalId' => $position['exchange']['internalId'],
        ];

        $options = [
            'projection' => [
                '_id' => 1,
                'side' => 1,
            ]
        ];

        $positions = $this->mongoDBLink->selectCollection('position')->find($find, $options);
        foreach ($positions as $pos) {
            if (empty($position['signal']['hedgeMode'])) {
                return false;
            }

            if ('MULTI' === $position['buyType'] || 'MULTI-STOP' === $position['buyType']) {
                return false;
            }

            if ($pos->side === $position['side']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compose the position from an entry signal or exit with http code.
     *
     * @param ExchangeCalls $ExchangeCalls
     * @param ExchangeHandler $exchangeHandler
     * @param BSONDocument $user
     * @param BSONDocument $exchange
     * @param array $signal
     * @return array
     */
    public function composePosition(
        ExchangeCalls $ExchangeCalls,
        ExchangeHandler $exchangeHandler,
        BSONDocument $user,
        BSONDocument $exchange,
        array $signal
    ) : array {
        //We define a fake provider to keep compatibility with legacy code.
        $provider = [
            "_id" => "1",
            "name" => "Manual",
            "isCopyTrading" => true, // To have compatibility with legacy code.
        ];
        $entryType = $this->getOrderType($signal);
        $side = !empty($signal['side']) ? strtoupper($signal['side']) : 'LONG';

        $multiFirstData = $this->composeMultiData($ExchangeCalls, $user, $signal, $exchange, 'original');
        $limitPrice = $multiFirstData['limitPrice'];
        $buyStopPrice = $multiFirstData['buyStopPrice'];
        $buyStopPrice = !$buyStopPrice ? $buyStopPrice : (float)$buyStopPrice;
        $leverage = $multiFirstData['leverage'];
        $positionSize = $multiFirstData['positionSize'];
        $amount = $multiFirstData['amount'];
        $trailingStopData = $this->extractTrailingStopData($signal, $limitPrice, $side);
        $reduceOrders = $this->composeReduceOrders($signal, $side);
        $reBuyTargets = $this->composeTargets($signal, 'reBuyTargets', $side);
        $takeProfitTargets = !$reduceOrders ? $this->composeTargets($signal, 'takeProfitTargets', $side) : false;
        $multiSecondData = 'MULTI' === $entryType || 'MULTI-STOP' === $entryType ?
            $this->composeMultiData($ExchangeCalls, $user, $signal, $exchange, 'short')
            : false;

        return [
            'user' => [
                '_id' => $user->_id,
            ],
            'exchange' => $this->extractExchangeDataForPosition($exchange),
            'signal' => $this->composeSignalForPositionCreation($signal),
            'provider' => $provider,
            'multiFirstData' => $multiFirstData,
            'multiSecondData' => $multiSecondData,
            'takeProfitTargets' => $takeProfitTargets,
            'DCAFromBeginning' => !empty($signal['DCAFromBeginning']),
            'DCAPlaceAll' => !empty($signal['DCAPlaceAll']),
            'reBuyProcess' => !empty($signal['DCAFromBeginning']),
            'reBuyTargets' => $reBuyTargets,
            'reduceOrders' => $reduceOrders,
            'createdAt' => new UTCDateTime(),
            'side' => $side,
            'amount' => $amount,
            'realAmount' => 0,
            'buyTTL' => $this->extractTTLs('buyTTL', $signal),
            'cancelBuyAt' => $this->extractCancelBuyAt($signal),
            'limitPrice' => $limitPrice,
            'buyType' => $entryType,
            'buyStopPrice' => $buyStopPrice,
            'status' => 0,
            'realBuyPrice' => $limitPrice,
            'avgBuyingPrice' => $limitPrice,
            'positionSize' => $positionSize,
            'closed' => false,
            'checkStop' => false,
            'buyPerformed' => false,
            'remainAmount' => 0,
            'lastUpdate' => new UTCDateTime(),
            'stopLossPercentage' => $this->extractStopLossForBuying($signal, $limitPrice, $side),
            'stopLossPrice' => $this->extractStopLossPriceFromSignal($signal),
            'stopLossPriority' => empty($signal['stopLossPriority']) ? 'percentage' : strtolower($signal['stopLossPriority']),
            'stopLossFollowsTakeProfit' => !empty($signal['stopLossFollowsTakeProfit']),
            'stopLossToBreakEven' => !empty($signal['stopLossToBreakEven']),
            'stopLossForce' => !empty($signal['stopLossForce']),
            'stopLossPercentageLastUpdate' => new UTCDateTime(),
            'trailingStopPercentage' => $trailingStopData ? $trailingStopData['trailingStopDistancePercentage'] : false,
            'trailingStopDistancePercentage' => $trailingStopData ? $trailingStopData['trailingStopDistancePercentage'] : false,
            'trailingStopTriggerPercentage' => $trailingStopData ? $trailingStopData['trailingStopTriggerPercentage'] : false,
            'trailingStopTriggerPrice' => $trailingStopData ? $trailingStopData['trailingStopTriggerPrice'] : false,
            'trailingStopTriggerPriority' => $trailingStopData ? $trailingStopData['trailingStopTriggerPriority'] : false,
            'trailingStopLastUpdate' => new UTCDateTime(),
            'trailingStopPrice' => false,
            'skipExitingAfterTP' => !empty($signal['skipExitingAfterTP']),
            'sellByTTL' => $this->extractTTLs('sellTTL', $signal),
            'exitByTTLAt' => $this->extractExitByTTLAt($signal),
            'forceExitByTTL' => $this->extractForceExitByTTL($signal),
            'updating' => false,
            'increasingPositionSize' => false,
            'version' => 3,
            'sellPerformed' => false,
            'accounted' => false,
            'lastCheckingOpenOrdersAt' => new UTCDateTime(),
            'checkingOpenOrdersLastGlobalCheck' => new UTCDateTime(),
            'checkingOpenOrders' => false,
            'locked' => true,
            'lockedAt' => new UTCDateTime(),
            'lockedBy' => 'entrySignal',
            'lockedFrom' => gethostname(),
            'lockId' => md5(uniqid(microtime(true) * rand(1, 1000), true)),
            'leverage' => $leverage,
            'realInvestment' => $this->getRealInvestment($positionSize, $signal, $leverage),
            'redisRemoved' => false,
            'copyTraderStatsDone' => false,
            'marginMode' => $signal['marginMode'] ?? $exchangeHandler->getDefaultMarginMode(),
            'order' => [],
            'trades_updated_at' => new UTCDateTime(),
        ];
    }

    /**
     * Compose data for multi entry positions.
     *
     * @param ExchangeCalls $ExchangeCalls
     * @param BSONDocument $user
     * @param array $signal
     * @param BSONDocument $exchange
     * @param string $suffix
     * @return array
     */
    private function composeMultiData(
        ExchangeCalls $ExchangeCalls,
        BSONDocument $user,
        array $signal,
        BSONDocument $exchange,
        string $suffix
    ) : array {
        $multiOrderType = $this->extractParameterFromMulti($signal, $suffix, 'orderType');
        $signalCopy = $signal;
        $signalCopy['orderType'] = $multiOrderType;
        if (isset($signalCopy['buyType'])) {
            unset($signalCopy['buyType']);
        }
        $multiOrderType = $this->getOrderType($signalCopy); //Todo: we aren't checking if it's a multi entry position, if so and the entry is "market", it won't be a good behavior.

        $limitPrice = $this->extractParameterFromMulti($signal, $suffix, 'limitPrice');
        if (!$limitPrice) {
            $limitPrice = $this->extractParameterFromMulti($signal, $suffix, 'price');
        }
        $limitPrice = $ExchangeCalls->getPrice2($signal['pair'], $multiOrderType, $limitPrice);
        if (!$limitPrice) {
            $this->sendHTTPCodeAndExit("Not able to get the price for this position.", 401);
        }
        $signal['positionSize'] = $this->getPositionSizeFromSignal($user, $exchange, $signal);

        $multiBuyStopPrice = $this->extractParameterFromMulti($signal, $suffix, 'buyStopPrice');
        $buyStopPrice = !empty($multiBuyStopPrice) ? $ExchangeCalls->getPriceToPrecision($multiBuyStopPrice, $signal['pair']) : false;
        $buyStopPrice = !$buyStopPrice ? $buyStopPrice : (float)$buyStopPrice;

        $leverage = $this->getLeverageFromSignal($signal);
        $amount = $this->getAmount($ExchangeCalls, $signal, $limitPrice, $suffix, $leverage);
        $side = !empty($signal['side']) ? strtoupper($signal['side']) : 'LONG';
        $orderSide = 'SHORT' === $side ? 'sell' : 'buy'; //Double check that this works.

        if ($leverage > 1 && $this->checkIfParameterIsTrue($signal, 'multiplyByLeverage')) {
            $signal['positionSize'] = $signal['positionSize'] * $leverage;
            //$amount = $amount * $leverage;
        }
        return [
            'orderType' => $multiOrderType,
            'limitPrice' => $limitPrice,
            'buyStopPrice' => $buyStopPrice,
            'positionSize' => $signal['positionSize'],
            'amount' => $amount,
            'side' => $orderSide, //If it doesn't work, check how is done in Position.php
            'leverage' => $leverage
        ];
    }

    /**
     * Check if the given parameter inside the update signal exists and if it's true/false.
     *
     * @param array $signal
     * @param string $parameter
     * @return bool
     */
    function checkIfParameterIsTrue(array $signal, string $parameter) : bool
    {
        if (!isset($signal[$parameter])) {
            return false;
        }

        if ('true' === $signal[$parameter]) {
            return true;
        }

        if (true === $signal[$parameter]) {
            return true;
        }

        if ($signal[$parameter]) {
            return true;
        }

        return false;
    }

    private function getAmount(ExchangeCalls $ExchangeCalls, array & $signal, float $limitPrice, string $suffix, int $leverage) : float
    {
        $amountFromSignal = $this->extractParameterFromMulti($signal, $suffix, 'amount');
        if (!empty($amountFromSignal) && $amountFromSignal > 0) {
            $signal['positionSize'] = $limitPrice * $amountFromSignal;
            $amount = (float)$amountFromSignal;
        } else {
            $amount = $signal['positionSize'] / $limitPrice;
        }
        if ($leverage > 1 && $this->checkIfParameterIsTrue($signal, 'multiplyByLeverage')) {
            $amount = $amount * $leverage;
        }
        $finalAmount = $ExchangeCalls->getAmountToPrecision($amount, $signal['pair']);
        if (!$finalAmount) {
            $this->sendHTTPCodeAndExit("Could not get the amount or amount below minimum.", 401);
        }

        return $finalAmount;
    }

    /**
     * @param BSONDocument $user
     * @param BSONDocument $exchange
     * @param array $signal
     * @return float
     */
    private function getPositionSizeFromSignal(BSONDocument $user, BSONDocument $exchange, array $signal) : float
    {
        //If amount parameter is sent, then we calculate the positionSize from the amount and price in a later step.
        if (!empty($signal['amount']) && $signal['amount'] > 0) {
            return 0.0;
        }

        if (!empty($signal['positionSize']) && is_numeric($signal['positionSize'])) {
            return (float)$signal['positionSize'];
        }

        $positionSizePercentageData = $this->isPositionSizeGoodForPercentage($signal);

        try {
            $container = DIContainer::getContainer();
            $monolog = new Monolog('trading_signals');
            $container->set('monolog', $monolog);
            /** @var BalanceService $balanceService */
            $balanceService = $container->get('balanceService');
            $balance = $balanceService->updateBalance($user, $exchange->internalId);

            if ($positionSizePercentageData['positionSizePercentageFromQuoteAvailable'] ||
                $positionSizePercentageData['positionSizePercentageFromQuoteTotal']) {
                $quote = $signal['quote'];
                $balanceField = $positionSizePercentageData['positionSizePercentageFromQuoteAvailable'] ? 'free' : 'total';
                if (empty($balance[$quote][$balanceField]) || $balance[$quote][$balanceField] < 0) {
                    $this->sendHTTPCodeAndExit("The quote $quote has 0 balance", 401);
                }
                $positionSizePercentage = $positionSizePercentageData['positionSizePercentageFromQuoteAvailable'] ?
                    $signal['positionSizePercentageFromQuoteAvailable'] : $signal['positionSizePercentageFromQuoteTotal'];
                $positionSize = $balance[$quote][$balanceField] / 100 * $positionSizePercentage;

                return (float)$positionSize;
            }

            if ($positionSizePercentageData['positionSizePercentageFromAccountAvailable'] ||
                $positionSizePercentageData['positionSizePercentageFromAccountTotal']) {
                $signalTotalQuote = $positionSizePercentageData['isQuoteUSDBased'] ? 'USD' : 'BTC';
                $balanceField = $positionSizePercentageData['positionSizePercentageFromAccountAvailable']
                    ? 'free' . $signalTotalQuote : 'total' . $signalTotalQuote;

                if (empty($balance['total'][$balanceField]) || $balance['total'][$balanceField] < 0) {
                    $this->sendHTTPCodeAndExit("The account balance based on $signalTotalQuote has 0 balance", 401);
                }

                $positionSizePercentage = $positionSizePercentageData['positionSizePercentageFromAccountAvailable'] ?
                    $signal['positionSizePercentageFromAccountAvailable'] : $signal['positionSizePercentageFromAccountTotal'];
                $positionSize = $balance['total'][$balanceField] / 100 * $positionSizePercentage;

                return (float)$positionSize;
            }

            $this->sendHTTPCodeAndExit("No position size parameter sent", 401);

            return 0.0;
        } catch (Exception $e) {
            $this->sendHTTPCodeAndExit("Error getting the position size from percentage", 401);
        }

        return 0.00;
    }

    /**
     * Check if the signal has parameters for managing the position size by percentage.
     * @param array $signal
     * @return array
     */
    private function isPositionSizeGoodForPercentage(array $signal) : array
    {
        $positionSizePercentageData = [
            'isQuoteUSDBased' => false,
            'isQuoteBTCBased' => false,
            'positionSizePercentageFromQuoteAvailable' => false,
            'positionSizePercentageFromQuoteTotal' => false,
            'positionSizePercentageFromAccountAvailable' => false,
            'positionSizePercentageFromAccountTotal' => false,
        ];

        if ($this->checkIfParameterFromSignalIsGoodPercentage($signal, 'positionSizePercentageFromQuoteAvailable')) {
            $positionSizePercentageData['positionSizePercentageFromQuoteAvailable'] = true;
            return $positionSizePercentageData;
        }

        if ($this->checkIfParameterFromSignalIsGoodPercentage($signal, 'positionSizePercentageFromQuoteTotal')) {
            $positionSizePercentageData['positionSizePercentageFromQuoteTotal'] = true;
            return $positionSizePercentageData;
        }


        $stableUSDCoins = ['USDT', 'USDC', 'BUSD', 'DAI'];
        $positionSizePercentageData['isQuoteUSDBased'] = in_array($signal['quote'], $stableUSDCoins);
        $BTCCoins = ['BTC', 'XBT'];
        $positionSizePercentageData['isQuoteBTCBased'] = in_array($signal['quote'], $BTCCoins);

        if (!$positionSizePercentageData['isQuoteUSDBased'] && !$positionSizePercentageData['isQuoteBTCBased']) {
            $this->sendHTTPCodeAndExit('No valid position size quote for total percentage', 401);
        }

        if ($this->checkIfParameterFromSignalIsGoodPercentage($signal, 'positionSizePercentageFromAccountAvailable')) {
            $positionSizePercentageData['positionSizePercentageFromAccountAvailable'] = true;
            return $positionSizePercentageData;
        }

        if ($this->checkIfParameterFromSignalIsGoodPercentage($signal, 'positionSizePercentageFromAccountTotal')) {
            $positionSizePercentageData['positionSizePercentageFromAccountTotal'] = true;
            return $positionSizePercentageData;
        }

        $this->sendHTTPCodeAndExit('Wrong or missing position size parameter', 401);

        return $positionSizePercentageData;
    }

    /**
     * Check that the parameter is a valid number bigger than 0 and lower or equal than 100.
     *
     * @param array $signal
     * @param string $parameter
     * @return bool
     */
    private function checkIfParameterFromSignalIsGoodPercentage(array $signal, string $parameter) : bool
    {
        if (empty($signal[$parameter])) {
            return false;
        }

        if (!is_numeric($signal[$parameter])) {
            return false;
        }

        if ($signal[$parameter] <= 0) {
            return false;
        }

        if ($signal[$parameter] > 100) {
            return false;
        }

        return true;
    }

    /**
     * Send and http code and exit
     * @param string $message
     * @param int $code
     * @return void
     */
    private function sendHTTPCodeAndExit(string $message, int $code): void
    {
        $this->Monolog->sendEntry('info', $message);
        http_response_code($code);
        print_r($message);
        exit();
    }

    /**
     * Extract the parameter having multi entries into consideration.
     * @param array $signal
     * @param string $suffix
     * @param string $field
     * @return bool|mixed
     */
    private function extractParameterFromMulti(array $signal, string $suffix, string $field)
    {
        if ('original' === $suffix || 'long' === $suffix) {
            if (!empty($signal[$field . '_long'])) {
                $parameter = $signal[$field . '_long'];
            } elseif (!empty($signal[$field])) {
                $parameter = $signal[$field];
            } else {
                $parameter = false;
            }
        } else {
            if (!empty($signal[$field . '_short'])) {
                $parameter = $signal[$field . '_short'];
            } elseif (!empty($signal[$field])) {
                $parameter = $signal[$field];
            } else {
                $parameter = false;
            }
        }

        return is_numeric($parameter) ? (float)$parameter : $parameter;
    }

    /**
     * Extract the real investment from the signal and position Size.
     *
     * @param bool|int|float $positionSize
     * @param array $signal
     * @param int $leverage
     * @return float
     */
    private function getRealInvestment($positionSize, array $signal, int $leverage) : float
    {
        if (isset($signal['realInvestment']) && is_numeric($signal['realInvestment'])) {
            return (float)$signal['realInvestment'];
        }

        $realInvestment = $positionSize / ($leverage > 0 ? $leverage : 1);

        return (float)$realInvestment;
    }

    /**
     * Extract the stop loss price from the signal.
     *
     * @param array $signal
     * @return bool|float
     */
    private function extractStopLossPriceFromSignal(array $signal)
    {
        if (!empty($signal['stopLossPrice']) && is_numeric($signal['stopLossPrice'])) {
            return (float)$signal['stopLossPrice'];
        }

        return false;
    }

    /**
     * Compose the stop loss percentage having into consideration the position side.
     *
     * @param array $signal
     * @param bool|float|int $price
     * @param string $side
     * @return bool|float
     */
    private function extractStopLossForBuying(array $signal, $price, string $side = 'LONG')
    {
        if (!$price || $price == 0)
            return false;

        if (isset($signal['stopLossPercentage'])) {
            $stopLoss = $signal['stopLossPercentage'];
        } elseif (isset($signal['stopLossPrice'])) {
            $stopLoss = number_format($signal['stopLossPrice'] / $price, 5);
        } else {
            $stopLoss = false;
        }

        if ($stopLoss) {
            if ($side == 'SHORT' && $stopLoss < 1) {
                $stopLoss = 2 - $stopLoss;
            }

            if ($side == 'LONG' && $stopLoss > 1) {
                $stopLoss = 2 - $stopLoss;
            }
            $stopLoss = (float) $stopLoss;
        }

        return $stopLoss;
    }

    /**
     * Extract the UTC Date Time from the signal for canceling the entry.
     *
     * @param array $signal
     * @return UTCDateTime
     */
    private function extractCancelBuyAt(array $signal) : UTCDateTime
    {
        if (!empty($signal['cancelBuyAt'])) {
            return new UTCDateTime($signal['cancelBuyAt']);
        }

        $buyTTL = $this->extractTTLs('buyTTL', $signal);

        return new UTCDateTime($signal['datetime'] + $buyTTL * 1000);
    }

    /**
     * Extract the UTC Date Time from the signal for exiting the position.
     * @param array $signal
     * @return UTCDateTime|null
     */
    private function extractExitByTTLAt(array $signal): ?UTCDateTime
    {
        if (!empty($signal['exitByTTLAt'])) {
            return new UTCDateTime($signal['exitByTTLAt']);
        }

        return null;
    }

    /**
     * Force exiting the position at the configured time if it's still opened at exitByTTLAt.
     *
     * @param array $signal
     * @return bool
     */
    private function extractForceExitByTTL(array $signal): bool
    {
        return !empty($signal['forceExitByTTL']);
    }

    /**
     * Get the seconds for actions based on time.
     *
     * @param string $ttl
     * @param array $signal
     * @return false|int
     */
    private function extractTTLs(string $ttl, array $signal)
    {
        $seconds = empty($signal[$ttl]) ? false : $signal[$ttl];

        if (!$seconds && $ttl == 'buyTTL') {
            $seconds = 5184000;
        }

        return $seconds;
    }

    /**
     * Compose the takeProfitsTargets or ReBuyTargets from the signal for a manual position having into consideration
     * the position side.
     *
     * @param array $signal
     * @param string $targetsType
     * @param string $side
     * @return array|bool
     */
    private function composeTargets(array $signal, string $targetsType, string $side = 'LONG')
    {
        //Targets Type: takeProfitTargets reBuyTargets
        if (empty($signal[$targetsType])) {
            return false;
        }

        $returnTargets = [];

        $targets = $signal[$targetsType];
        foreach ($targets as $target) {
            $targetId = $target['targetId'];
            $amountPercentage = $target['amountPercentage'] ?? null;
            $priceTargetPercentage = $target['priceTargetPercentage'];
            if ($targetsType == 'takeProfitTargets') {
                if ($side == 'SHORT' && $target['priceTargetPercentage'] > 1) {
                    $priceTargetPercentage = 2 - $target['priceTargetPercentage'];
                }
                if ($side == 'LONG' && $target['priceTargetPercentage'] < 1) {
                    $priceTargetPercentage = 2 - $target['priceTargetPercentage'];
                }
                $returnTargets[$targetId] = [
                    'targetId' => $targetId,
                    'priceTargetPercentage' => $priceTargetPercentage,
                    'priceTarget' => empty($target['priceTarget']) ? false : $target['priceTarget'],
                    'pricePriority' => empty($target['pricePriority']) ? 'percentage' : $target['pricePriority'],
                    'amountPercentage' => $amountPercentage,
                    'updating' => false,
                    'done' => false,
                    'orderId' => false,
                    'postOnly' => $target['postOnly'] ?? false
                ];
            } else {
                if ($side == 'SHORT' && $target['priceTargetPercentage'] < 1) {
                    $priceTargetPercentage = 2 - $target['priceTargetPercentage'];
                }
                if ($side == 'LONG' && $target['priceTargetPercentage'] > 1) {
                    $priceTargetPercentage = 2 - $target['priceTargetPercentage'];
                }

                $returnTargets[$targetId] = [
                    'targetId' => $targetId,
                    'triggerPercentage' => $priceTargetPercentage,
                    'priceTarget' => empty($target['priceTarget']) ? false : $target['priceTarget'],
                    'pricePriority' => empty($target['pricePriority']) ? 'percentage' : $target['pricePriority'],
                    'quantity' => $amountPercentage,
                    'newInvestment' => $target['newInvestment'] ?? null,
                    'buying' => false,
                    'done' => false,
                    'orderId' => false,
                    'cancel' => false,
                    'skipped' => false,
                    'buyType' => 'LIMIT',
                    'postOnly' => $target['postOnly'] ?? false
                ];
            }
        }

        return $returnTargets;
    }

    /**
     * Check if there are reduce parameters in the entry signal and compose the reduceTarget.
     *
     * @param array $signal
     * @param string $side
     * @return bool|array
     */
    public function composeReduceOrders(array $signal, string $side)
    {
        if (empty($signal['reduceTargetPercentage']) || empty($signal['reduceAvailablePercentage'])) {
            return false;
        }

        $error = false;
        $signal['reduceOrderType'] = empty($signal['reduceOrderType']) ? 'limit' : $signal['reduceOrderType'];
        if ($signal['reduceOrderType'] == 'market') {
            $error = "Type can't be market from an entry signal.";
        }
        if (!empty($signal['reduceRecurring']) && $signal['reduceOrderType'] == 'market') {
            $error = "Recurring option is incompatible with market order type.";
        }
        if ($side == 'LONG' && $signal['reduceTargetPercentage'] <= 1) {
            $error = "Target price has to be bigger than 0 for LONG positions in entry signals.";
        }
        if ($side == 'SHORT' && $signal['reduceTargetPercentage'] >= 1) {
            $error = "Target price has to be lower than 0 for SHORT positions in entry signals.";
        }

        $reduceOrders[1] = [
            'targetId' => 1,
            'type' => $signal['reduceOrderType'],
            'targetPercentage' => $signal['reduceTargetPercentage'],
            'priceTarget' => empty($signal['reduceTargetPrice']) ? false : $signal['reduceTargetPrice'],
            'pricePriority' => empty($signal['reduceTargetPriority']) ? false : $signal['reduceTargetPriority'],
            'availablePercentage' => $signal['reduceAvailablePercentage'],
            'recurring' => !empty($signal['reduceRecurring']),
            'persistent' => !empty($signal['reducePersistent']),
            'orderId' => false,
            'done' => !empty($error),
            'error' => $error,
            'postOnly' => $signal['reducePostOnly'] ?? false,
        ];

        return $reduceOrders;
    }

    /**
     * Compose the trailing stop data having into consideration the position side.
     *
     * @param array $signal
     * @param bool|float|int $price
     * @param string $side
     * @return array|bool
     */
    private function extractTrailingStopData(array $signal, $price, string $side = 'LONG')
    {
        if (!$price || $price == 0) {
            return false;
        }

        $trailingStopData = false;

        if (!empty($signal['trailingStopDistancePercentage'])) {
            $trailingStopData['trailingStopDistancePercentage'] = $signal['trailingStopDistancePercentage'];
        }
        if (!empty($signal['trailingStopTriggerPercentage'])) {
            $trailingStopData['trailingStopTriggerPercentage'] = $signal['trailingStopTriggerPercentage'];
        } elseif (isset($signal['trailingStopTriggerPrice']) && $signal['trailingStopTriggerPrice'] > 0) {
            $trailingStopData['trailingStopTriggerPercentage'] = round($signal['trailingStopTriggerPrice'] / $price, 2);
        }

        if (empty($trailingStopData['trailingStopTriggerPercentage']) || empty($trailingStopData['trailingStopDistancePercentage'])) {
            $trailingStopData = false;
        }


        if (!empty($trailingStopData)) {
            $trailingStopData['trailingStopTriggerPrice'] = empty($signal['trailingStopTriggerPrice']) ? false : $signal['trailingStopTriggerPrice'];
            $trailingStopData['trailingStopTriggerPriority'] = empty($signal['trailingStopTriggerPriority']) ? false
                : strtolower($signal['trailingStopTriggerPriority']);
            if ($side == 'SHORT') {
                if (isset($trailingStopData['trailingStopDistancePercentage']) && $trailingStopData['trailingStopDistancePercentage'] < 1) {
                    $trailingStopData['trailingStopDistancePercentage'] = 2 - $trailingStopData['trailingStopDistancePercentage'];
                }
                if (isset($trailingStopData['trailingStopTriggerPercentage']) && $trailingStopData['trailingStopTriggerPercentage'] > 1) {
                    $trailingStopData['trailingStopTriggerPercentage'] = 2 - $trailingStopData['trailingStopTriggerPercentage'];
                }
            }
            if ($side == 'LONG') {
                if (isset($trailingStopData['trailingStopDistancePercentage']) && $trailingStopData['trailingStopDistancePercentage'] > 1) {
                    $trailingStopData['trailingStopDistancePercentage'] = 2 - $trailingStopData['trailingStopDistancePercentage'];
                }
                if (isset($trailingStopData['trailingStopTriggerPercentage']) && $trailingStopData['trailingStopTriggerPercentage'] < 1) {
                    $trailingStopData['trailingStopTriggerPercentage'] = 2 - $trailingStopData['trailingStopTriggerPercentage'];
                }
            }
        }

        return $trailingStopData;
    }

    /**
     * Select the proper leverage based on user preferences.
     *
     * @param array $signal
     * @return int
     */
    public function getLeverageFromSignal(array $signal): int
    {
        $leverage = $signal['leverage'] ?? 1;

        if ($leverage >= 1 && $leverage <= 125) {
            $leverage = intval($leverage);
        } else {
            $leverage = 1;
        }

        return $leverage;
    }

    /**
     * Extract the order type from the signal.
     *
     * @param array $signal
     * @return string
     */
    private function getOrderType(array $signal) : string
    {
        if (isset($signal['buyType'])) {
            $type = strtoupper($signal['buyType']);
        } elseif (isset($signal['orderType'])) {
            $type = strtoupper($signal['orderType']);
        }

        if (empty($type)) {
            $type = 'LIMIT';
        }

        if ('STOP_LOSS_LIMIT' === $type) {
            $type = 'STOP-LIMIT';
        }

        return $type;
    }

    /**
     * Parse some data from the signal and add the provider data manually for legacy compatibility.
     * @param array $signal
     * @return array
     */
    private function composeSignalForPositionCreation(array $signal) : array
    {
        $signal['_id'] = isset($signal['_id']) ? new ObjectId($signal['_id']) : false;
        $signal['providerId'] = 1;
        $signal['providerName'] = 'Manual';
        $signal['price'] = !isset($signal['price']) || !$signal['price'] ? false : (float)$signal['price'];
        $signal['limitPrice'] = !isset($signal['limitPrice']) || !$signal['limitPrice'] ? false : (float)$signal['limitPrice'];
        $signal['datetime'] = new UTCDateTime($signal['datetime']);
        $signal['buyStopPrice'] = !isset($signal['buyStopPrice']) || !$signal['buyStopPrice'] ? false : (float)$signal['buyStopPrice'];

        return $signal;
    }

    /**
     * Extract the exchange data for including in a position.
     *
     * @param object $exchange
     * @return array
     */
    private function extractExchangeDataForPosition(object $exchange) : array
    {
        return [
            '_id' => $exchange->_id,
            'name' => $exchange->name,
            'exchangeId' => $exchange->exchangeId,
            'exchangeName' => $exchange->exchangeName,
            'internalId' => $exchange->internalId,
            'internalName' => $exchange->internalName,
            'areKeysValid' => $exchange->areKeysValid,
            'exchangeType' => $exchange->exchangeType ?? 'spot'
        ];
    }

    /**
     * Create a fake order and update the position with the entry data.
     *
     * @param RedisHandler $RedisHandlerZignalyQueue
     * @param ExchangeHandler $exchangeHandler
     * @param string $positionId
     * @param array $position
     * @return void
     */
    public function createImportData(
        RedisHandler $RedisHandlerZignalyQueue,
        ExchangeHandler $exchangeHandler,
        string $positionId,
        array $position
    ): void
    {
        $setPosition = [
            'realAmount' => $position['amount'],
            'remainAmount' => $position['amount'],
            'realPositionSize' => $position['positionSize'],
            'avgBuyingPrice' => $position['limitPrice'],
            'orders.1' => $this->createFakeOrder($exchangeHandler, $position),
            'status' => 9,
            'buyPerformed' => true,
            'reBuyProcess' => true,
        ];

        $pushPosition = [
            'trades' => $this->createFakeTrade($exchangeHandler, $position)
        ];

        $positionDocument = $this->setPosition($positionId, $setPosition, true, $pushPosition);
        if ($positionDocument) {
            $message['positionId'] = $positionId;
            $message = json_encode($message, JSON_PRESERVE_ZERO_FRACTION);
            $RedisHandlerZignalyQueue->addSortedSet('stopLoss', time(), $message, true);
            $RedisHandlerZignalyQueue->addSortedSet('reduceOrdersQueue', time(), $message, true);
        } else {
            $setPosition = [
                'closed' => true,
                'closedAt' => new UTCDateTime(),
                'status' => 69,
            ];
            $this->setPosition($positionId, $setPosition);
            $this->sendHTTPCodeAndExit("Not able to create the import order.", 401);
        }
    }

    public function closeAllPositionsBecauseWrongKey(BSONDocument $position)
    {
        if ('Zignaly' === $position->exchange->name) {
            return;
        }

        $find = [
            'closed' => false,
            'user._id' => $position->user->_id,
            'exchange.internalId' => $position->exchange->internalId,
        ];

        $set = [
            '$set' => [
                'closed' => true,
                'closedAt' => new UTCDateTime(),
                'status' => 32,
                'Note' => "Closed manually because keys didn't work",
            ]
        ];

        $this->mongoDBLink->selectCollection($this->collectionName)->updateMany($find, $set);
    }

    /**
     * Create a fake order for the import entry signal.
     *
     * @param ExchangeHandler $exchangeHandler
     * @param array $position
     * @return array
     */
    private function createFakeOrder(ExchangeHandler $exchangeHandler, array $position) : array
    {
        return [
            "orderId" => 1,
            "status" => "closed",
            "type" => "entry",
            "price" => $position['limitPrice'],
            "amount" => $position['amount'],
            "cost" => $exchangeHandler->calculateOrderCostZignalyPair($position['signal']['pair'], $position['amount'], $position['limitPrice']),
            "transacTime" => new UTCDateTime(),
            "orderType" => "IMPORT",
            "done" => true,
        ];
    }

    /**
     * Create a fake trade for the import entry signal.
     *
     * @param ExchangeHandler $exchangeHandler
     * @param array $position
     * @return array
     */
    private function createFakeTrade(ExchangeHandler $exchangeHandler, array $position) : array
    {
        $tradeCost = number_format(
            $exchangeHandler->calculateOrderCostZignalyPair(
                $position['signal']['pair'],
                $position['amount'],
                $position['limitPrice']
            ), 12, '.', '');
        return [
            "symbol" => $position['signal']['pair'],
            "id" => 1,
            "orderId" => 1,
            "price" => $position['limitPrice'],
            "qty" => $position['amount'],
            "cost" => $tradeCost,
            "quoteQty" => $tradeCost,
            "commission" => "0",
            "commissionAsset" => "BNB",
            "time" => time() * 1000,
            "isBuyer" => strtoupper($position['side']) == 'LONG',
            "isMaker" => true,
            "isBestMatch" => true
        ];
    }

    /**
     * Look the position that matches the signal parameters: signalId, key, base and quote and return the position's bson document.
     *
     * @param array $signal
     * @param bool $setBuyPerformed
     * @return BSONDocument|null
     */
    public function getActivePositionsFromExchangeKeyAndSignalId(array $signal, bool $setBuyPerformed = true) : ?BSONDocument
    {
        $find = [
            'signal.signalId' => $signal['signalId'],
            'signal.key' => $signal['key'],
            'signal.base' => $signal['base'],
            'signal.quote' => $signal['quote'],
            'closed' => false,
        ];

        if ($setBuyPerformed) {
            $find['buyPerformed'] = true;
        }

        if (!empty($signal['exitSide'])) {
            $find['side'] = strtoupper($signal['exitSide']);
        }

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);
    }

    /**
     * Get the list of positions where the extra triggers need to be reviewed.
     *
     * @return Cursor
     */
    public function getPositionsForCheckingExtraParameters()
    {
        $find = [
            'closed' => false,
            '$or' => [
                [
                    'buyPerformed' => false,
                    'status' => 1,
                    'cancelBuyAt' => [
                        '$lte' => new UTCDateTime(),
                    ]
                ],
                [
                    'exitByTTLAt' => [
                        '$lte' => new UTCDateTime(),
                    ]
                ],
                [
                    'manualCancel' => true,
                    'status' => 1,
                ],
                [
                    '$expr' => [
                        '$gt' => [
                            ['$convert' => [ 'input' => '$sellByTTL', 'to' => 'double', 'onError' =>  0] ],
                            0
                        ]
                    ]
                ]
            ],
        ];

        $options = [
            //'noCursorTimeout' => true,
            'projection' => [
                '_id' => 1,
                'paperTrading' =>1,
                'testNet' => 1,
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Get the list of positions where there are pending reBuys.
     *
     * @return Cursor
     */
    public function getPositionsWithPendingReBuys()
    {
        $find = [
            'locked' => false,
            'closed' => false,
            'reBuyProcess' => true,
        ];

        $options = [
            //'noCursorTimeout' => true,
            'projection' => [
                '_id' => 1,
                'paperTrading' =>1,
                'testNet' => 1,
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Get the list of positions currently opened for a futures exchange.
     *
     * @return Cursor
     */
    public function getPositionsFromFutures()
    {
        $find = [
            'closed' => false,
            'exchange.exchangeType' => 'futures',
            'paperTrading' => false,
            'testNet' => false,
            'status' => [
                '$gt' => 1,
            ]
        ];

        $options = [
            //'noCursorTimeout' => true,
            'projection' => [
                '_id' => 1,
                'paperTrading' =>1,
                'testNet' => 1,
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Get the list of positions where the accounting needs to be done.
     *
     * @return Cursor
     */
    public function getPositionsForAccounting($excludeAccountingDelayed = false)
    {
        $find = [
            'closed' => true,
            'sellPerformed' => true,
            'accounted' => false,
        ];

        if ($excludeAccountingDelayed) {
            $find['$or'] = [
                [
                    'accountingDelayedCount' => [ '$exists' => false],
                ],
                [
                //  'accountingDelayedCount' => ['$lte' => 5],
                    'accountingDelayedUntil' => ['$lte' => new UTCDateTime()]
                ],
            ];
        }

        $options = [
            //'noCursorTimeout' => true,
            'projection' => [
                '_id' => 1,
                'closedAt' => 1,
                'paperTrading' =>1,
                'testNet' => 1,
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Look for position from the given contracts' info.
     *
     * @param ObjectId $userId
     * @param string $exchangeInternalId
     * @param string $symbol
     * @param string $side
     * @param string $providerId
     * @return string
     */
    public function getPositionFromContractInfoForUserExchange(
        ObjectId $userId,
        string $exchangeInternalId,
        string $symbol,
        string $side,
        string $providerId = null
    ) {
        $find = [
            'closed' => false,
            'user._id' => $userId,
            'signal.pair' => $symbol,
        ];

        if ($providerId !== null) {
            $find['provider._id'] = $providerId;
        } else {
            $find['exchange.internalId'] = $exchangeInternalId;
        }

        $side = strtoupper($side);
        if ($side != 'BOTH') {
            $find['side'] = $side;
        }

        $options = [
            'projection' => [
                '_id' => 1
            ]
        ];

        $position = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find, $options);

        return !empty($position->_id) ? $position->_id->__toString() : '';
    }

    /**
     * Look for a position that contain certain order id.
     *
     * @param ObjectId $userId
     * @param string $exchangeInternalId
     * @param string $orderId
     * @return string
     */
    public function getPositionFromOrderIdForUserExchange(ObjectId $userId, string $exchangeInternalId, string $orderId)
    {
        $find = [
            'user._id' => $userId,
            'exchange.internalId' => $exchangeInternalId,
            'orders.' . $orderId => [
                '$exists' => true,
            ]
        ];

        $options = [
            'projection' => [
                '_id' => 1
            ]
        ];

        $position = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find, $options);

        return !empty($position->_id) ? $position->_id->__toString() : '';
    }

    /**
     * Look for a position by user, providerId and signalId.
     *
     * @param ObjectId $userId
     * @param string $providerId
     * @param string $signalId
     * @return array|ObjectId
     */
    public function getPositionFromManualCreation(ObjectId $userId, string $providerId, string $signalId)
    {
        if (strlen($providerId) > 5) {
            $providerId = new ObjectId($providerId);
        }

        $find = [
            "user._id" => $userId,
            "signal.providerId" => $providerId,
            "signal.signalId" => $signalId,
        ];

        $options = [
            'projection' => [
                '_id' => 1,
                'signal.signalId' => 1,
            ],
            'limit' => 1,
        ];

        $tries = 0;
        while ($tries < 50) {
            $position = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find, $options);
            if (isset($position->signal->signalId) && $position->signal->signalId == $signalId) {
                return $position->_id->__toString();
            }

            $tries++;
            sleep(3);
        }

        return ['error' => ['code' => 69]];
    }

    public function getPositionFromManualUpdate(ObjectId $positionId, ObjectId $userId)
    {
        $find = [
            '_id' => $positionId,
            '$or' => [
                ['user._id' => $userId],
                ['provider.userId' => $userId->__toString()]
            ],
        ];

        $options = [
            'projection' => [
                '_id' => 1,
                'updating' => 1,
            ]
        ];

        $tries = 0;
        while ($tries < 5) {
            $position = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find, $options);
            if (isset($position->updating) && empty($position->updating)) {
                return $position->_id->__toString();
            }

            $tries++;
            sleep(3);
        }

        return ['error' => ['code' => 29]];
    }

    /**
     * Given a signal Id, look for all the positions opened from it where an entry order has been filled.
     *
     * @param object|string $signalId
     * @param bool $paperTrading
     * @return Cursor
     */
    public function getPositionsBySignalId($signalId, $paperTrading = false)
    {
        $signalId = is_object($signalId) ? $signalId : new ObjectId($signalId);

        $find = [
            'signal._id' => $signalId,
            'buyPerformed' => true,
        ];

        if (!$paperTrading)
            $find['paperTrading'] = false;

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find);
    }

    /**
     * @param string $providerId
     * @param ObjectId $userId
     * @return int
     */
    public function countCurrentOpenPositionsPerService(string $providerId, ObjectId $userId)
    {
        $find = [
            'closed' => false,
            'user._id' => $userId,
            'provider._id' => $providerId
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->countDocuments($find);
    }

    public function getPositionsForCopyTradingStats($providerId, $userId, $closed, $isLocal)
    {
        $find = [
            'closed' => $closed,
            'user._id' => $userId,
            'provider._id' => $providerId,
            'copyTraderStatsDone' => false,
            'paperTrading' => false,
            'testNet' => false,
        ];

        if ($closed)
            $find['accounting.done'] = true;
        else
            $find['status'] = 9;

        if ($isLocal) {
            unset($find['paperTrading']);
            unset($find['testNet']);
        }

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find);
    }


    /**
     * Compute the balance from current open positions for a given user and provider.
     *
     * @param Accounting $Accounting
     * @param ObjectId $userId
     * @param string $providerId
     * @return float|int
     */
    public function getBalanceFromOpenPositions(
        Accounting $Accounting,
        ObjectId   $userId,
        string     $providerId
    ) {
        $providerId = new ObjectId($providerId);

        $find = [
            'user._id' => $userId,
            'signal.providerId' => $providerId,
            'closed' => false,
        ];

        $positions = $this->mongoDBLink->selectCollection($this->collectionName)->find($find);

        $consumedBalanceFromOpenPositions = 0.0;

        foreach ($positions as $position) {
            if (isset($position->trades) && $position->trades) {
                list($positionSizeEntry) = $Accounting->estimatedPositionSize($position);
                $consumedBalanceFromOpenPositions += $positionSizeEntry;
            } else {
                $consumedBalanceFromOpenPositions += is_object($position->realPositionSize)
                    ? $position->realPositionSize->__toString() : $position->realPositionSize;
            }
            //Todo: We are not having into consideration the reBuys/DCAs orders placed in the exchange. We need to add that amount to the consumed balance.
        }

        return $consumedBalanceFromOpenPositions;
    }

    /**
     * Get the list of positions from a given exchange for the user from the given date if provider or all of them.
     *
     * @param ObjectId $userId
     * @param ObjectId $exchangeId
     * @param bool|UTCDateTime $fromDate
     * @return Cursor
     */
    public function getSoldPositionsFromExchange(ObjectId $userId, ObjectId $exchangeId, $fromDate = false)
    {
        $find = [
            'closed' => true,
            'user._id' => $userId,
            'exchange._id' => $exchangeId,
            'accounting.done' => true,
            'paperTrading' => false,
            'testNet' => false,
        ];

        if ($fromDate) {
            $find['accounting.closingDate'] = [
                '$gt' => $fromDate
            ];
        }

        $options = [
            'projection' => [
                'signal.quote' => true,
                'accounting.closingDate' => true,
                'accounting.totalFees' => true,
                'accounting.sellQuoteBTCPrice' => true,
                'accounting.sellBTCUSDCPrice' => true,
                // new fields needed for bitmex
                'exchange.name' => true,
                'exchange.exchangeType' => true,
                'exchange.isTestnet' => true,
                'exchange.internalId' => true,
                'signal.pair' => true
            ],
            //'noCursorTimeout' => true,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     *
     * Return the list of closed positions where the accounting exists for a given provider, so they have been sold.
     *
     * @param ObjectId $userId
     * @param string $providerId
     * @param int $limit
     * @return Cursor
     */
    public function getSoldPositions(ObjectId $userId, string $providerId, $limit = 500)
    {
        $find = [
            'closed' => true,
            'user._id' => $userId,
            'provider._id' => $providerId,
            'provider.isCopyTrading' => true,
            'accounting.done' =>true,
        ];

        $options = [
            'projection' => [
                'exchange' => true,
                'accounting.openingDate' => true,
                'accounting.closingDate' => true,
                'signal.base' => true,
                'signal.quote' => true,
                'signal.positionSizePercentage' => true,
                'accounting.buyAvgPrice' => true,
                'accounting.sellAvgPrice' => true,
                'side' => true,
                'accounting.buyTotalQty' => true,
                'accounting.netProfit' => true,
                'provider.exchange' => true,
                'status' => true,
                'leverage' => true,
                'positionSize' => true,
                'signal.signalId' => true,
                // new fields needed for bitmex
                'signal.pair' => true
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * Return the position for a user or copy-trader
     *
     * @param ObjectId $userId
     * @param string $positionId
     * @return BSONDocument|false
     */
    public function getPositionByIdForUserOrCopyTrader(ObjectId $userId, string $positionId)
    {
        $find = [
            '_id' => $this->parseMongoDBObject($positionId),
            '$or' => [
                ['user._id' => $userId],
                ['provider.userId' => $userId->__toString()]
            ],
        ];

        //Todo: projection.

        $position = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);

        return empty($position->user) ? false : $position;
    }

    /**
     * Check if the DCAs has been done.
     * @param BSONDocument $position
     * @return bool
     */
    public function checkIfDCAHasBeenDone(BSONDocument $position)
    {
        if (!isset($position->reBuyTargets) || !$position->reBuyTargets) {
            return false;
        }

        foreach ($position->reBuyTargets as $target) {
            if ($target->done) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the reBuyTarget ID from the given orderId or false if none.
     * @param BSONDocument $position
     * @param string $orderId
     * @return bool|int
     */
    public function getCurrentReEntryTargetId(BSONDocument $position, string $orderId)
    {
        if (!isset($position->reBuyTargets) || !$position->reBuyTargets) {
            return false;
        }

        foreach ($position->reBuyTargets as $reBuyTarget) {
            if ($reBuyTarget->orderId == $orderId && isset($reBuyTarget->targetId)) {// && !$reBuyTarget->done)
                return $reBuyTarget->targetId;
            }
        }

        return false;
    }

    /**
     * If it's a multi position be sure that the side/targets are correct and cancel the remaining multi-order.
     * @param BSONDocument $position
     * @return BSONDocument|object|null
     */
    public function handleMultiPositions(BSONDocument $position)
    {
        if ('MULTI' !== $position->buyType) {
            return $position;
        }

        foreach ($position->orders as $order) {
            if (!empty($order->side) && !empty($order->originalEntry)) {
                if (empty($order->done)) {
                    if ($this->cancelOrder($order->orderId, $position)) {
                        return $this->getPosition($position->_id);
                    } else {
                        $this->Monolog->sendEntry('critical', "Error canceling order: {$order->orderId}");
                        //Todo: what we do if cancel fails?
                    }
                }
            } else {
                $this->Monolog->sendEntry('critical', "There are orders with originalEntry = false: {$order->orderId}");
            }
        }

        return $position;
    }

    /**
     * Check existing trades and remove them from the array.
     * @param ObjectId $positionId
     * @param array $trades
     * @return array
     */
    private function removeExistingTrades(ObjectId $positionId, array $trades)
    {
        $find = [
            '_id' => $positionId,
        ];

        $options = [
            'projection' => [
                '_id' => 0,
                'trades' => 0,
            ]
        ];

        $position = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find, $options);
        if (empty($position->trades)) {
            return $trades;
        }
        $currentOrders = [];
        foreach ($position->trades as $trade) {
            $currentOrders[] = $trade->orderId;
        }
        if (empty($currentOrders)) {
            return $trades;
        }

        $tradesToInsert = [];

        foreach ($trades as $trade) {
            if (!in_array($trade['orderId'], $currentOrders)) {
                $tradesToInsert[] = $trade;
            } else {
                //Todo: send notification.
            }
        }

        return $tradesToInsert;
    }

    public function pushDataAndReturnDocument($positionId, $field, $data)
    {
        if ('trades' === $field) {
            $data = $this->removeExistingTrades($positionId, $data);
        }
        $find = [
            '_id' => $positionId,
        ];
        if (empty($data)) {
            return $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);
        }

        $update = [
            '$push' => [
                $field => [
                    '$each' => $data,
                ],
            ]
        ];

        if ('trades' === $field) {
            $update['$set'] = [
                'trades_updated_at' => new UTCDateTime()
            ];
        }
        $options = [
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        $position = $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $update, $options);

        /*if (!empty($position->closed)) {
            $this->copyDocumentToClosedPositionCollection($position);
        }*/

        return isset($position->status) ? $position : false;
    }

    /**
     * Unset the data from the given array.
     *
     * @param ObjectId $positionId
     * @param array $unset
     * @return array|object|null
     */
    public function unsetPosition(ObjectId $positionId, array $unset)
    {
        $find = [
            '_id' => $positionId,
        ];

        $unset = [
            '$unset' => $unset,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $unset);
    }

    /**
     * @param string|ObjectId $positionId
     * @param array $setPosition
     * @param bool $updateLastUpdate
     * @param bool|array $pushData
     * @return bool|ObjectId
     */
    public function setPosition($positionId, array $setPosition, bool $updateLastUpdate = true, $pushData = false)
    {
        if ($updateLastUpdate) {
            $setPosition['lastUpdate'] = new UTCDateTime();
        }

        $find = [
            '_id' => $this->parseMongoDBObject($positionId),
        ];

        if (!empty($setPosition['closed'])) {
            $setPosition['locked'] = false;
        }

        $set = [
            '$set' => $setPosition
        ];

        if ($pushData) {
            $set['$push'] = $pushData;
        }

        $options = [
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        //$this->Monolog->sendEntry('debug', "Updating all this thing: ", $setPosition);

        $position = $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $set, $options);
        if (!isset($position->status)) {
            //$this->RedisTriggersWatcher->configureMonolog($this->Monolog);
            //$this->RedisTriggersWatcher->preparePosition($position);
            /*if ($position->closed) {
                $this->copyDocumentToClosedPositionCollection($position);
            }*/
        //} else {
            $this->Monolog->sendEntry('debug', "Update failed");
        }
        return isset($position->status) ? $position : false;
    }

    /**
     * @return mixed
     */
    public function getPositionsForRemoving()
    {
        $daysToLookBack = 30;
        $timeToCheck = time() - $daysToLookBack * 86400;
        $find = [
            'closed' => true,
            '$or' => [
                [
                    'closedAt' => [
                        '$lte' => new UTCDateTime($timeToCheck * 1000),
                    ]
                ],
                [
                    'closedAt' => [
                        '$exists' => false,
                    ]
                ]
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find);
    }

    /**
     * @param ObjectId $positionId
     * @return mixed
     */
    public function removePosition(ObjectId $positionId)
    {
        $find = [
            '_id' => $positionId,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionNameClosed)->deleteOne($find);
    }
    /**
     * @param BSONDocument $position
     * @return mixed
     */
    private function copyDocumentToClosedPositionCollection(BSONDocument $position)
    {
        $find = [
            '_id' => $position->_id,
        ];

        $update = [
            '$set' => $position
        ];

        $options = [
            'upsert' => true,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionNameClosed)->updateOne($find, $update, $options);
    }

    /**
     * Check if the parameters to update contain relevant data from order and update the order if so.
     *
     * @param ObjectId|string $positionId
     * @param array $setPosition
     * @param string $orderId
     * @return bool
     */
    public function updateNewOrderField($positionId, array $setPosition, string $orderId)
    {
        $fields = ['status', 'price', 'amount', 'cost', 'done'];
        $newSet = [];
        foreach ($fields as $field) {
            if (!empty($setPosition["orders.$orderId.$field"])) {
                $newSet["order.$.$field"] = $setPosition["orders.$orderId.$field"];
            }
        }

        if (!empty($newSet)) {
            $find = [
                '_id' => $this->parseMongoDBObject($positionId),
                'order.orderId' => $orderId
            ];

            $set = [
                '$set' => $newSet
            ];

            return $this->mongoDBLink->selectCollection($this->collectionName)->updateOne($find, $set)->getModifiedCount() > 0;
        }

        return false;
    }

    public function unlockPositionFromProcess(string $positionId, string $processName)
    {
        $find = [
            '_id' => $this->parseMongoDBObject($positionId),
            'locked' => true,
            'lockedBy' => $processName,
            'lockedFrom' => gethostname(),
        ];

        $set = [
            '$set' => [
                'locked' => false,
            ],
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->updateOne($find, $set)->getModifiedCount();
    }

    public function unlockPosition($positionId, $dateField = false, $flag = false, $timestamp = false)
    {
        $find = [
            '_id' => $this->parseMongoDBObject($positionId),
        ];

        $set = [
            '$set' => [
                'locked' => false,
            ],
        ];

        if ($dateField)
            $set['$set'][$dateField] = $timestamp
                ? new UTCDateTime($timestamp) : new UTCDateTime();

        if ($flag)
            $set['$set'][$flag] = false;

        return $this->mongoDBLink->selectCollection($this->collectionName)->updateOne($find, $set)->getModifiedCount();
    }

    public function manageExpiredOrder(
        BSONDocument  $position,
        ExchangeOrder $order
    ) {
        $orderId = $order->getId();

        $setPosition = [
            "orders.$orderId.status" => $order->getStatus(),
            "orders.$orderId.skipped"   => true
        ];

        if ($position->orders->$orderId->type == 'takeProfit') {
            $targetId = $this->getTargetIdByOrderId($position, $orderId, 'takeProfitTargets');
            if ($targetId) {
                $setPosition["takeProfitTargets.$targetId.skipped"] = true;
                $setPosition["takeProfitTargets.$targetId.expired"] = true;
            }
        } else if ($position->orders->$orderId->type == 'exit') {
            $targetId = $this->getTargetIdByOrderId($position, $orderId, 'reduceOrders');
            if ($targetId) {
                $setPosition["reduceOrders.$targetId.skipped"] = true;
                $setPosition["reduceOrders.$targetId.expired"] = true;
            }
        } else if ($position->orders->$orderId->type == 'stopLoss') {
            
        } else {
            $targetId = $this->getTargetIdByOrderId($position, $orderId, 'reBuyTargets');
            if (isset($targetId) && $targetId) {
                $setPosition["reBuyTargets.$targetId.skipped"] = true;
                $setPosition["reBuyTargets.$targetId.expired"] = true;
            }
        }   

        return $setPosition;
    }

    /**
     * Updates the position from the given order and check if it needs to be closed.
     *
     * @param BSONDocument $position
     * @param string $orderId
     * @param float $orderPrice
     * @param string $orderStatus
     * @return bool|ObjectId
     */
    public function updateOrderAndCheckIfPositionNeedToBeClosed(
        BSONDocument $position,
        string       $orderId,
        float        $orderPrice,
        string       $orderStatus
    ) {
        global $Accounting;

        $amount = $Accounting->getRealAmount($position, $orderId);
        $orderPrice = number_format($orderPrice, 12, '.', '');

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

        $setPosition["orders.$orderId.status"] = $orderStatus;
        $setPosition["orders.$orderId.done"] = true;
        $setPosition["orders.$orderId.price"] = $orderPrice;
        $setPosition["orders.$orderId.amount"] = $amount;
        // LFERN $setPosition["orders.$orderId.cost"] = $orderPrice * $amount;
        $setPosition["orders.$orderId.cost"] = $exchangeHandler
            ->calculateOrderCostZignalyPair(
                $positionMediator->getSymbol(),
                $amount,
                $orderPrice
            );

        if ($position->orders->$orderId->type == 'takeProfit') {
            $targetId = $this->getTargetIdByOrderId($position, $orderId, 'takeProfitTargets');
            if ($targetId) {
                $setPosition["takeProfitTargets.$targetId.done"] = true;
                $setPosition["takeProfitTargets.$targetId.filledAt"] = $this->getDateTimeFromLastTrade($position, $orderId);
                $stopLossPercentageForBreakEven = 'SHORT' === $position->side ? 0.997 : 1.003;
                if (!empty($position->stopLossFollowsTakeProfit)) {
                    $stopLossTargetId = $targetId - 1;
                    if (0 === $stopLossTargetId) {
                        $setPosition['stopLossPercentage'] = $stopLossPercentageForBreakEven;
                        $setPosition['stopLossPercentageLastUpdate'] = new UTCDateTime();
                        $setPosition['stopLossPriority'] = 'percentage';
                    } else {
                        if (!empty($position->takeProfitTargets->$stopLossTargetId)) {
                            $setPosition['stopLossPercentage'] = $position->takeProfitTargets->$stopLossTargetId->priceTargetPercentage;
                            $setPosition['stopLossPercentageLastUpdate'] = new UTCDateTime();
                            $setPosition['stopLossPriority'] = 'percentage';
                        }
                    }
                } elseif (!empty($position->stopLossToBreakEven)) {
                    $setPosition['stopLossPercentage'] = $stopLossPercentageForBreakEven;
                    $setPosition['stopLossPriority'] = 'percentage';
                    $setPosition['stopLossPercentageLastUpdate'] = new UTCDateTime();
                }
            }
        } elseif ($position->orders->$orderId->type == 'exit') {
            $targetId = $this->getTargetIdByOrderId($position, $orderId, 'reduceOrders');
            if ($targetId) {
                $setPosition["reduceOrders.$targetId.done"] = true;
                $setPosition["reduceOrders.$targetId.filledAt"] = $this->getDateTimeFromLastTrade($position, $orderId);
            }
        }

        $symbol = $positionMediator->getSymbol();//$position->signal->base . $position->signal->quote;
        list(, $remainingAmount) = $Accounting->recalculateAndUpdateAmounts($position);
        $isAmountGood = false;
        if ($remainingAmount > 0) {
            $isAmountGood = $this->ExchangeCalls->checkIfValueIsGood('amount', 'min', $remainingAmount, $symbol);
        }

        $isPersistent = $this->checkPositionPersistent($position) && $position->orders->$orderId->type != 'stopLoss';

        if (!$isAmountGood && !$isPersistent) {
            $this->cancelPendingOrders($position, ['buy', 'entry']);
            $position = $this->getPosition($position->_id);
            list(, $remainingAmount) = $Accounting->recalculateAndUpdateAmounts($position);
            $isAmountGood = $this->ExchangeCalls->checkIfValueIsGood('amount', 'min', $remainingAmount, $symbol);
        }

        if ((!$isAmountGood && !$isPersistent) || $position->status == 54) {
            $position = $this->getPosition($position->_id);
            $setPosition['closed'] = true;
            $setPosition['closedAt'] = new UTCDateTime();
            if ($position->status != 54) {
                $tradesSideType = isset($position->side) && strtolower($position->side) == 'short' ? 'buy' : 'sell';
                $realExitPrice = number_format($Accounting->getAveragePrice($position, $tradesSideType), 12, '.', '');
                $setPosition['sellPerformed'] = true;
                $setPosition['realSellPrice'] = (float)$realExitPrice;
                if ($position->orders->$orderId->type == 'takeProfit') {
                    $setPosition['status'] = 17;
                } elseif (isset($position->orders->$orderId->positionStatus) && is_numeric($position->orders->$orderId->positionStatus)) {
                    $setPosition['status'] = $position->orders->$orderId->positionStatus;
                } elseif ($position->orders->$orderId->type == 'exit') {
                    $setPosition['status'] = 97;
                }

                $this->Monolog->sendEntry('info', "Closing position because remaining amount is below minimal notional.", $setPosition);
            }
        } elseif ($position->orders->$orderId->type == 'takeProfit') {
            $setPosition['reBuyProcess'] = true;
            $setPosition['lastReBuyProcessAt'] = new UTCDateTime();
        }

        $this->updateNewOrderField($position->_id, $setPosition, $orderId);
        return $this->setPosition($position->_id, $setPosition, true);
    }

    /**
     * Check if the position has any reduce order with persistent flag and if there is any DCA pending.
     *
     * @param BSONDocument $position
     * @return bool
     */
    public function checkPositionPersistent(BSONDocument $position)
    {
        if (empty($position->reduceOrders) || empty($position->reBuyTargets)) {
            return false;
        }

        foreach ($position->reduceOrders as $reduceOrder) {
            if (!empty($reduceOrder->persistent)) {
                $isTherePersistent = true;
            }
        }

        if (empty($isTherePersistent)) {
            return false;
        }

        foreach ($position->reBuyTargets as $reBuyTarget) {
            if (empty($reBuyTarget->done) && empty($reBuyTarget->cancel) && empty($reBuyTarget->skipped)) {
                print_r($reBuyTarget);
                return true;
            }
        }

        return false;
    }

    /**
     * Get response data from sending order and update the given position.
     *
     * @param BSONDocument $position
     * @param ExchangeOrder $order
     * @param object $reBuyTarget
     * @return array
     */
    public function updatePositionFromReBuyOrder(BSONDocument $position, ExchangeOrder $order,
                                                 object       $reBuyTarget)
    {
        $reBuyTargetId = $reBuyTarget->targetId;
        $isIncreasingPositionSize = isset($reBuyTarget->subId);
        //$this->Monolog->sendEntry('debug', "Order ({$order->getId()}) sent OK");

        $setPosition = [
            "orders.{$order->getId()}" => [
                'orderId' => $order->getId(),
                'status' => $order->getStatus(),
                'type' => 'entry',
                'price' => $order->getPrice(),
                'amount' => $order->getAmount(),
                'cost' => $order->getCost(),
                'transacTime' => new UTCDateTime($order->getTimestamp()),
                'orderType' => $order->getType(),
                'done' => false,
                'isIncreasingPositionSize' => true,
            ],
            "reBuyTargets.$reBuyTargetId.buying" => true,
            "reBuyTargets.$reBuyTargetId.orderId" => $order->getId(),
            "reBuyTargets.$reBuyTargetId.updated" => new UTCDateTime(),
            "increasingPositionSize" => true,
        ];

        if (!$isIncreasingPositionSize && empty($position->DCAPlaceAll)) {
            while ($reBuyTargetId > 1) {
                $reBuyTargetId--;
                if (isset($position->reBuyTargets) && !$position->reBuyTargets->$reBuyTargetId->done && !$position->reBuyTargets->$reBuyTargetId->skipped) {
                    $this->Monolog->sendEntry('debug', "Skipping target $reBuyTargetId");
                    $setPosition["reBuyTargets.$reBuyTargetId.skipped"] = true;
                }
            }
        }

        return $setPosition;
    }

    public function updatePositionFromReBuyOrderError($position, $order, $reBuyTarget)
    {
        global $Exchange;

        $reBuyTargetId = $reBuyTarget->targetId;

        if (!isset($order['error'])) {
            $order['error'] = 'Unknown';
        }
        $setPosition = [
            "reBuyTargets.$reBuyTargetId.error" => [
                'msg' => $order['error'],
            ],
            "reBuyTargets.$reBuyTargetId.skipped" => true,
            "reBuyTargets.$reBuyTargetId.updated" => new UTCDateTime(),
            "increasingPositionSize" => false,
            "reBuyProcess" => true,
        ];
        $logMethod = $Exchange->getLogMethodFromError($order['error']);
        $this->Monolog->sendEntry($logMethod, "Order sent FAIL for " . $position->_id->__toString(), $order);
        //Todo: send notification.

        return $setPosition;
    }

    public function updatePositionFromSellSignal($position, ExchangeOrder $order, $status, $orderType, $originalAmount)
    {
        $type = $orderType == 'LIMIT' ? 'takeProfit' : 'stopLoss';
        $setPosition = [
            "orders.{$order->getId()}" => [
                'orderId' => $order->getId(),
                'status' => $order->getStatus(),
                'type' => $type,
                'price' => $order->getPrice(),
                'amount' => $order->getAmount(),
                'originalAmount' => $originalAmount,
                'cost' => $order->getCost(),
                'transacTime' => new UTCDateTime($order->getTimestamp()),
                'orderType' => $order->getType(),
                'done' => false,
                'positionStatus' => $status,
                'reduceOnly' => $order->getReduceOnly(),
                'clientOrderId' => $order->getRecvClientId(),
            ],
        ];
        if ($type == 'takeProfit') {
            $setPosition["takeProfitTargets"] = $this->composeTakeProfitTargetsFromSellLimitSignal($position, $order);
            $setPosition["updating"] = false;
        } else {
            $setPosition['sellingByStopLoss'] = true;
            $setPosition['status'] = $status;
        }

        $orderArray[] = [
            'orderId' => $order->getId(),
            'status' => $order->getStatus(),
            'type' => $type,
            'price' => $order->getPrice(),
            'amount' => $order->getAmount(),
            'originalAmount' => $originalAmount,
            'cost' => $order->getCost(),
            'transacTime' => new UTCDateTime($order->getTimestamp()),
            'orderType' => $order->getType(),
            'done' => false,
            'positionStatus' => $status,
            'reduceOnly' => $order->getReduceOnly(),
            'clientOrderId' => $order->getRecvClientId(),
        ];

        $pushOrder = [
            'order' => [
                '$each' => $orderArray,
            ],
        ];

        $this->setPosition($position->_id, $setPosition, true, $pushOrder);
    }

    /**
     * Update a position from a order error when trying to close it.
     *
     * @param BSONDocument $position
     * @param array $order
     * @param string $orderType
     * @param ExchangeCalls $ExchangeCalls
     */
    public function updatePositionFromSellSignalError(
        BSONDocument  $position,
        array         $order,
        string        $orderType,
        ExchangeCalls $ExchangeCalls
    ) {
        global $Status;

        $status = $Status->getPositionStatusFromError($order['error']);
        $method = $status == 99 ? 'error' : 'warning';

        $this->Monolog->sendEntry($method, "Order $orderType with status $status, for position: "
            . $position->_id->__toString() . " failed: " . $order['error']);

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeType = $positionMediator->getExchangeType();

        if ($status == 86 && $exchangeType == 'futures') {
            $liquidated = $this->checkIfPositionHasBeenLiquidated($ExchangeCalls, $position);
        }

        if (empty($liquidated) && $status != 99) {
            $setPosition['status'] = $status;
            $setPosition['closed'] = true;
            $setPosition['closedAt'] = new UTCDateTime();
        }

        if (!empty($setPosition)) {
            $this->setPosition($position->_id, $setPosition);
        }
    }

    /**
     * Get locked positions for a long time.
     *
     * @param $minutes
     * @return Cursor
     */
    public function getLockedPositionsForLongTime($minutes)
    {
        $timeLimit = (time() - $minutes * 60) * 1000;
        $find = [
            'closed' => false,
            'locked' => true,
            'lockedAt' => [
                '$lt' => new UTCDateTime($timeLimit),
            ],
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find);
    }

    /**
     * @param ObjectId $userId
     * @param string $providerId
     * @return array
     */
    public function lastLiquidationDateFromService(ObjectId $userId, string $providerId)
    {
        $find = [
            'closed' => true,
            'user._id' => $userId,
            'provider._id' => $providerId,
            'status' => 101
        ];

        $options = [
            'sort' => [
                'accounting.closingDate' => -1
            ],
            'limit' => 1,
            'projection' => [
                'accounting.closingDate' => 1,
                'closedAt' => 1,
            ]
        ];

        $positions = $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);

        foreach ($positions as $position) {
            $closingDate = !empty($position->accounting->closingDate) ? $position->accounting->closingDate : $position->closedAt;
            return [$closingDate, $position->_id];
        }

        return [false, false];
    }

    /**
     * Check if a position has been liquidated and close it if so.
     *
     * @param ExchangeCalls $ExchangeCalls
     * @param BSONDocument $position
     * @param bool|ExchangeOrder[] $forceOrders
     * @return bool
     */
    public function checkIfPositionHasBeenLiquidated(ExchangeCalls $ExchangeCalls, BSONDocument $position, $forceOrders = false)
    {
        if (!$forceOrders) {
            $forceOrders = $ExchangeCalls->getForceOrders($position);
        }

        if (isset($forceOrders['error'])) {
            $this->Monolog->sendEntry('critical', 'Error getting forced orders ' . $forceOrders['error']);
            return false;
        }

        if (empty($forceOrders)) {
            return false;
        }

        list(, $remainAmount) = $this->Accounting->recalculateAndUpdateAmounts($position);
        foreach ($forceOrders as $order) {
            if ($order->getAmount() == $remainAmount) {
                $method =  'warning' ;
                $this->Monolog->sendEntry($method, "Position liquidated");

                $trades = $ExchangeCalls->getTrades(
                    $position,
                    $order->getId(),
                    !empty($order->getTrades())? $order : false
                );
                if (!empty($trades)) {
                    $position = $this->pushDataAndReturnDocument($position->_id, 'trades', $trades);
                } else {
                    $position = $this->getPosition($position->_id);
                }
                list(, $remainAmount) = $this->Accounting->recalculateAndUpdateAmounts($position);
                if ($remainAmount <= 0) {
                    $setPosition = [
                        "status" => 101,
                        "closed" => true,
                        "sellPerformed" => true,
                        'closedAt' => new UTCDateTime(),
                        "orders.{$order->getId()}" => [
                            'orderId' => $order->getId(),
                            'status' => $order->getStatus(),
                            'type' => 'exit',
                            'price' => $order->getPrice(),
                            'amount' => $order->getAmount(),
                            'cost' => $order->getCost(),
                            'transacTime' => new UTCDateTime($order->getTimestamp()),
                            'orderType' => $order->getType(),
                            'done' => true,
                            'subType' => 'LIQUIDATION',
                        ],
                    ];
                    $orderArray[] = [
                        'orderId' => $order->getId(),
                        'status' => $order->getStatus(),
                        'type' => 'exit',
                        'price' => $order->getPrice(),
                        'amount' => $order->getAmount(),
                        'cost' => $order->getCost(),
                        'transacTime' => new UTCDateTime($order->getTimestamp()),
                        'orderType' => $order->getType(),
                        'done' => true,
                        'subType' => 'LIQUIDATION',
                    ];

                    $pushOrder = [
                        'order' => [
                            '$each' => $orderArray,
                        ],
                    ];

                    return !empty($this->setPosition($position->_id, $setPosition, true, $pushOrder));
                }
            }
        }

        return false;
    }

    public function updatePositionFromTakeProfitOrder($position, ExchangeOrder $order, $targetId, $amount)
    {
        $this->Monolog->sendEntry('debug', "Take Profit order placed for: " . $position->_id->__toString());
        $position = $this->getPosition($position->_id);
        if (isset($position->remainAmount)) {
            $currentRemainAmount = is_object($position->remainAmount) ? $position->remainAmount->__toString() : $position->remainAmount;
        } else {
            $currentRemainAmount = is_object($position->realAmount) ? $position->realAmount->__toString() : $position->realAmount;
        }
        $remainAmount = (float)($currentRemainAmount - $amount);
        $setPosition = [
            "orders.{$order->getId()}" => [
                'orderId' => $order->getId(),
                'status' => $order->getStatus(),
                'type' => 'takeProfit',
                'price' => $order->getPrice(),
                'amount' => $order->getAmount(),
                'cost' => $order->getCost(),
                'transacTime' => new UTCDateTime($order->getTimestamp()),
                'orderType' => $order->getType(),
                'done' => false,
                'clientOrderId' => $order->getRecvClientId(),
            ],
            "takeProfitTargets.$targetId.orderId" => $order->getId(),
            "remainAmount" => $remainAmount,
        ];

        $orderArray[] = [
            'orderId' => $order->getId(),
            'status' => $order->getStatus(),
            'type' => 'takeProfit',
            'price' => $order->getPrice(),
            'amount' => $order->getAmount(),
            'cost' => $order->getCost(),
            'transacTime' => new UTCDateTime($order->getTimestamp()),
            'orderType' => $order->getType(),
            'done' => false,
            'reduceOnly' => $order->getReduceOnly(),
            'clientOrderId' => $order->getRecvClientId(),
        ];

        $pushOrder = [
            'order' => [
                '$each' => $orderArray,
            ],
        ];

        $this->setPosition($position->_id, $setPosition, false, $pushOrder);
    }

    function updatePositionFromTakeProfitOrderError($position, $order, $targetId, $amount)
    {
        global $Accounting, $newUser, $Notification, $Status;

        $this->Monolog->sendEntry('warning', "Take Profit order with amount $amount placed failed for: " .
            $position->_id->__toString() . ': ' . $order['error']);

        if (!isset($order['error']))
            $order['error'] = 'Unknown';

        $setPosition = [
            'error' => [
                'msg' => $order['error'],
            ],
        ];

        $status = $Status->getPositionStatusFromError($order['error']);
        $close = in_array($status, [32, 36, 38]);
        list (, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($position);
        $positionMediator = PositionMediator::fromMongoPosition($position);
        $isAmountGood = $this->ExchangeCalls->checkIfValueIsGood('amount', 'min', $remainAmount,
            $positionMediator->getSymbol());//$position->signal->base . $position->signal->quote);

        if (!$isAmountGood && $close) {
            $setPosition['closed'] = true;
            $setPosition['status'] = $status;
        }

        $headMessage = "*ERROR:* The take profit $targetId couldn't be placed because of the following error: \n" .
            $Status->getPositionStatusText($status) . "\n";
        $user = $newUser->getUser($position->user->_id);
        $domain = $user->projectId == 'ct01' ? 'app.altexample.com' : 'example.net';
        $positionUrl = 'https://' . $domain . '/app/position/' . $position->_id->__toString();
        $endingMessage = isset($setPosition['closed']) && $setPosition['closed']
            ? "The position [$positionUrl]($positionUrl) will be closed."
            : "The position [$positionUrl]($positionUrl) needs your attention.";
        $message = $headMessage . $endingMessage;
        $Notification->sendPositionUpdateNotification($user, $message);

        $this->setPosition($position->_id, $setPosition, false);
    }

    //PRIVATE FUNCTIONS
    private function composeTakeProfitTargetsFromSellLimitSignal($position, ExchangeOrder $order)
    {
        $setTargets = false;
        if ($position->takeProfitTargets) {
            foreach ($position->takeProfitTargets as $target) {
                $targetId = $target->targetId;
                if ($target->done)
                    $setTargets[$targetId] = $position->takeProfitTargets->$targetId;
            }
        }

        if (isset($position->avgBuyingPrice)) {
            $avgBuyingPrice = is_object($position->avgBuyingPrice) ? $position->avgBuyingPrice->__toString() : $position->avgBuyingPrice;
        } else {
            $avgBuyingPrice = is_object($position->realBuyPrice) ? $position->realBuyPrice->__toString() : $position->realBuyPrice;
        }
        $sellingPrice = $order->getPrice();
        $priceTargetPercentage = number_format($sellingPrice / $avgBuyingPrice, 3, '.', '');
        $targetId = isset($targetId) ? $targetId++ : 1;
        $setTargets[$targetId] = [
            "targetId" => $targetId,
            "priceTargetPercentage" => $priceTargetPercentage,
            "amountPercentage" => "1",
            "updating" => false,
            "done" => false,
            "orderId" => $order->getId(),
        ];


        return $setTargets;
    }

    private function extractFilledTargetFactor(ExchangeOrder $order, $target)
    {
        $filledAmount = $order->getFilled();
        $intendedAmount = $order->getAmount();
        if (isset($target->amountPercentage)) {
            $targetFactor = $target->amountPercentage;
        } else if (isset($target->quantity)) {
            $targetFactor = $target->quantity;
        } else {
            $targetFactor = $target->availablePercentage;
        }

        return number_format($filledAmount * $targetFactor / $intendedAmount, 4, '.', '');
    }

    /**
     * Return the date of the last trade for the given order.
     *
     * @param BSONDocument $position
     * @param string $orderId
     * @return UTCDateTime
     */
    private function getDateTimeFromLastTrade(BSONDocument $position, string $orderId)
    {
        $lastTradeTime = time() * 1000;

        if (isset($position->trades) && $position->trades) {
            foreach ($position->trades as $trade) {
                if ($trade->orderId == $orderId && isset($trade->time) && is_object($trade->time)) {
                    $lastTradeTime = $trade->time->__toString();
                }
            }
        }

        return new UTCDateTime($lastTradeTime);
    }

    /**
     * Look for the targetId in the targets array that matches the orderId.
     *
     * @param BSONDocument $position
     * @param string $orderId
     * @param string $targets
     * @return bool
     */
    private function getTargetIdByOrderId(BSONDocument $position, string $orderId, string $targets)
    {
        if (!isset($position->$targets) || !$position->$targets)
            return false;

        foreach ($position->$targets as $target)
            if ($target->orderId == $orderId)
                return $target->targetId;

        return false;
    }

    private function getTargetsAndTargetIdFromOrderId($position, $orderId)
    {
        $targetType = ['reBuyTargets', 'takeProfitTargets', 'reduceOrders'];
        foreach ($targetType as $targets) {
            if (empty($position->$targets)) {
                continue;
            }

            foreach ($position->$targets as $target) {
                if ($target->orderId == $orderId) {
                    return [$targets, $target->targetId];
                }
            }
        }

        return [false, false];
    }

    private function parseMongoDBObject($element)
    {
        return is_object($element) ? $element : new ObjectId($element);
    }

    /**
     * Return the total volume generated by provider/copy-trader.
     *
     * @param ObjectId $providerId
     * @return float|int
     */
    public function sumPositionVolumeFromProvider(ObjectId $providerId)
    {
        $pipeline = [
            [
                '$match' => [
                    'closed' => true,
                    'signal.providerId' => $providerId,
                    'accounting.done' => true,
                    'testNet' => false,
                    'paperTrading' => false,
                ]
            ],
            [
                '$group' => [
                    '_id' => null,
                    'volumeBuy' => [
                        '$sum' => [
                            '$multiply' => [
                                '$accounting.buyTotalQty',
                                '$accounting.buyAvgPrice',
                            ]
                        ]
                    ],
                    'volumeSell' => [
                        '$sum' => [
                            '$multiply' => [
                                '$accounting.sellTotalQty',
                                '$accounting.sellAvgPrice',
                            ]
                        ]
                    ],

                ]
            ]
        ];

        $results = $this->mongoDBLink->selectCollection($this->collectionName)->aggregate($pipeline);
        $volumeBuy = 0;
        $volumeSell = 0;
        foreach ($results as $result) {
            if (empty($result->volumeBuy)) {
                $volumeBuy = 0;
            } else {
                $volumeBuy = is_object($result->volumeBuy) ? $result->volumeBuy->__toString() : $result->volumeBuy;
            }
            if (empty($result->volumeSell)) {
                $volumeSell = 0;
            } else {
                $volumeSell = is_object($result->volumeSell) ? $result->volumeSell->__toString() : $result->volumeSell;
            }
        }

        return (float)$volumeBuy + $volumeSell;
    }

    /**
     * Reset copyTaderStatsDone for position
     *
     * @param ObjectId|string $positionId
     * @return void
     */
    public function resetCopyTraderStats($positionId)
    {
        $find = [
            '_id' => is_object($positionId) ? $positionId : new ObjectId($positionId)
        ];

        $update = [
            '$set' => [
                'copyTraderStatsDone' => false,
            ],
        ];

        $this->mongoDBLink->selectCollection($this->collectionName)->updateOne($find, $update);
    }

    /**
     * @param array $find
     * @param int|null $skip
     * @param ObjectId|null $lastId
     * @return array
     */
    private function getPositionsInBatches(array $find, int $skip = null, ObjectId $lastId = null): array
    {
        $options = ['projection' => ['_id' => 1, 'accounting' => 1]];
        if ($skip) {
            $options['limit'] = $skip;
        }
        $result = $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);

        $response = [];

        foreach ($result as $document) {
            $id = $document->_id;
            if ($lastId && $id->getTimestamp() > $lastId->getTimestamp()) {
                $lastId = $id;
            }

            $key = (string) $id;
            $response[$key] = [
                'id' => $id,
                'timestamp' => $document->accounting->closingDate->toDateTime()->getTimestamp()
            ];
        }

        return [$response, $lastId];
    }
}