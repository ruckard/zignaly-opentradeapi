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


use Zignaly\exchange\ExchangeOrder;
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use Zignaly\service\ZignalyLastTradesService;

class CheckOrdersCCXT
{
    /**
     * @var Accounting
     */
    private $Accounting;

    private $Monolog;

    /** @var newPositionCCXT  */
    private $newPositionCCXT;
    private $RabbitMQ;
    private $checkOrdersByTime = false;
    private $position;
    private $ExchangeCalls;
    private $forceCheck;
    /** @var RedisHandler */
    private $RedisHandlerZignalyQueue;

    /**
     * Mediator for position within context.
     *
     * @var \Zignaly\Mediator\PositionMediator
     */
    private $positionMediator;

    /**
     * @var RedisLockController
     */
    private $RedisLockController;

    /**
     * CheckOrdersCCXT constructor.
     * @param \MongoDB\Model\BSONDocument $position
     * @param ExchangeCalls $ExchangeCalls
     * @param newPositionCCXT $newPositionCCXT
     * @param Monolog $Monolog
     * @param bool $forceCheck
     * @throws Exception
     */
    public function __construct(
        \MongoDB\Model\BSONDocument $position,
        ExchangeCalls &$ExchangeCalls,
        newPositionCCXT &$newPositionCCXT,
        Monolog $Monolog,
        $forceCheck = false
    ) {
        $container = DIContainer::getContainer();
        if ($container->has('monolog')) {
            $this->Monolog = $container->get('monolog');
        } else {
            $this->Monolog = $Monolog;
        }
        $this->newPositionCCXT = $newPositionCCXT;
        $this->RabbitMQ = new RabbitMQ();
        $this->position = $position;
        // $this->positionMediator = new PositionMediator($position);
        $this->positionMediator = PositionMediator::fromMongoPosition($position);
        $this->ExchangeCalls = $ExchangeCalls;
        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());
        $this->RedisLockController = $container->get('RedisLockController');
        $this->RedisHandlerZignalyQueue = $container->get('redis.queue');

        $this->forceCheck = $forceCheck;
        $this->Accounting = $container->get('accounting');
    }

    /**
     * Cancel the entry order and update the position with resulting data.
     *
     * @param string $processName
     * @param int $status
     * @param string $orderId
     * @param bool $remainingEntryOrders
     * @return bool
     */
    public function cancelInitialOrder(string $processName, int $status, string $orderId, bool $remainingEntryOrders)
    {
        $setPosition = $this->cancelEntryOrder($orderId, $status, $remainingEntryOrders);
        if ($setPosition) {
            $this->position = $this->newPositionCCXT->setPosition($this->position->_id, $setPosition, true);
            $this->newPositionCCXT->updateNewOrderField($this->position->_id, $setPosition, $orderId);
            if (isset($setPosition['updating']) && $setPosition['updating'] && (!isset($setPosition['closed']) || !$setPosition['closed'])) {
                $this->position = $this->newPositionCCXT->handleMultiPositions($this->position);
                $this->sendTakeProfits();
                $this->sendStopOrders();
                $this->sendReduceOrder();
                $this->sendCopyTradingSignal();
                $this->sendNotification('checkFirstBuySuccess', false, ['trigger' => $processName]);
            }
        }

        if ($this->position->closed && !empty($this->position->DCAFromBeginning)) {
            $this->newPositionCCXT->cancelPendingOrders($this->position, ['entry']);
        }

        return !$this->position->closed;
    }

    /**
     * Check open orders and update the position if they are closed.
     *
     * @param bool $beforeReEntry
     * @param bool $entryOnly
     * @param bool $forceCheck
     * @return bool
     */
    public function checkOrders($beforeReEntry = false, $entryOnly = false, $forceCheck = false)
    {
        $this->checkOrdersByTime = $forceCheck;

        foreach ($this->position->orders as $order) {
            $orderId = $order->orderId;
            $order = $this->position->orders->$orderId;

            if ($this->checkIfOrderNeedToBeChecked($order, $entryOnly)) {
                if ($order->type == 'buy' || $order->type == 'entry') {
                    if ($this->position->status == 1) {
                        $setPosition = $this->checkEntryOrders($order);
                    } else {
                        $setPosition = $this->checkReEntryOrder($order);
                    }
                    $updateLastUpdate = true;
                } else {
                    $setPosition = $this->checkExitOpenOrders($order, $beforeReEntry);
                    $updateLastUpdate = false;
                }

                if ($setPosition) {
                    $this->position = $this->newPositionCCXT->setPosition($this->position->_id, $setPosition, $updateLastUpdate);
                    $this->newPositionCCXT->updateNewOrderField($this->position->_id, $setPosition, $orderId);
                    if (!empty($setPosition['updating']) && empty($setPosition['closed'])) {
                        $this->position = $this->newPositionCCXT->handleMultiPositions($this->position);
                        $this->sendTakeProfits();
                        $this->sendStopOrders();
                        $this->sendReduceOrder();
                        $this->sendCopyTradingSignal();
                    }
                    if (!empty($setPosition['buyPerformedAt'])) {
                        $event = [
                            'type' => 'openPosition',
                            'userId' => $this->position->user->_id->__toString(),
                            'parameters' => [
                                'positionId' => $this->position->_id->__toString(),
                            ],
                            'timestamp' => time(),
                        ];
                        $this->RedisHandlerZignalyQueue->insertElementInList('eventsQueue', json_encode($event));
                    }
                }
                if (!empty($this->position->closed)) {
                    $this->newPositionCCXT->cancelPendingOrders($this->position, ['stop']);
                    $this->sendClosedPositionToAccountingQueue();
                }
            }
        }
        $this->checkIfNeedToBeClosedBecauseNoOpenOrders();

        return true;
    }

    /**
     * Send the current position to the accounting queue if it's closed.
     */
    private function sendClosedPositionToAccountingQueue()
    {
        if (!empty($this->position->closed)) {
            $score = time();
            $positionsId = [];
            $positionsId[$this->position->_id->__toString()] = $score;
            $this->RedisHandlerZignalyQueue->addSortedSetPipeline('accountingQueue', $positionsId);
        }
    }

    /**
     * Check if given order need to be checked based on parameters and status.
     *
     * @param object $order
     * @param bool $entryOnly
     * @return bool
     */
    private function checkIfOrderNeedToBeChecked(object $order, $entryOnly)
    {
        if ($this->position->closed) {
            return false;
        }

        if ($order->status == 'cancelled'
            || $order->status == 'canceled'
            || $order->status == ExchangeOrderStatus::Expired
        ) {
            return false;
        }

        if (!isset($order->cost)) {
            $order->cost = 0;
        }

        if ($order->done && $order->cost > 0) {
            return false;
        }

        if ('market' === $order->orderType) {
            return true;
        }

        if (!$entryOnly) {
            return true;
        }

        if ('entry' === $order->type) {
            return true;
        }

        $side = isset($this->position->side) ? strtolower($this->position->side) : 'long';
        if ('buy' === $order->type && $side == 'long') {
            return true;
        }

        return false;
    }

    private function checkIfNeedToBeClosedBecauseNoOpenOrders()
    {
        global $Accounting;

        $lastOrder = $this->getLastOrderOrFalseIfUndone($this->position->orders);
        if (!$lastOrder)
            return false;

        list (, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($this->position);
        //$this->Monolog->sendEntry('debug', "Last order is $lastOrder, remain amount: $remainAmount");
        if ($lastOrder != 'takeProfit' || $remainAmount > 0)
            return false;

        //$this->Monolog->sendEntry('debug', "Closing because there isn't remaining amount and orders are done.");
        $setPosition = [
            'closed' => true,
            'closedAt' => new \MongoDB\BSON\UTCDateTime(),
            'status' => 17,
            'sellPerformed' => true,
        ];

        $this->position = $this->newPositionCCXT->setPosition($this->position->_id, $setPosition, true);
        $this->sendClosedPositionToAccountingQueue();
    }

    private function getLastOrderOrFalseIfUndone($orders)
    {
        $orderType = false;

        foreach ($orders as $order) {
            if ($order->status == 'cancelled') {
                continue;
            }

            if (!isset($order->cost)) {
                $order->cost = 0;
            }

            if (!$order->done || $order->cost == 0) {
                return false;
            }

            $orderType = $order->type;
        }

        return $orderType;
    }

    /**
     * Given and order from position->order check if it's done and update the position with it's data. If it's isn't
     * done, check the status of the order and other parameters.
     *
     * @param object $order
     * @return array|bool
     */
    private function checkEntryOrders(object $order)
    {
        global $Exchange;

        $this->Monolog->sendEntry('info', "Checking entry ".$order->orderId);

        $orderId = $order->orderId;
        $orderFromExchange = $this->ExchangeCalls->getOrder($this->position, $orderId);
        $orderStatus = is_object($orderFromExchange) ? $orderFromExchange->getStatus() : false;

        $orderSide = empty($order->side) ? $this->position->side : $order->side;
        if (!is_object($orderFromExchange) && isset($orderFromExchange['error'])) {
            if ($this->skipError($orderFromExchange['error'])) {
                $method = $Exchange->getLogMethodFromError($orderFromExchange['error']);
                $this->Monolog->sendEntry($method, "Skipping check", $orderFromExchange);
            } else {
                $setPosition = $this->checkIfThereIsRemainingMultiOrders($orderId, $orderFromExchange, $orderSide, 'error');
            }
        } elseif ($orderStatus == 'cancelled' || $orderStatus == 'canceled') {
            $resultOrderType = 'canceled';
            $fakeOrderFromExchange['error'] = "Canceled";
            $setPosition = $this->checkIfThereIsRemainingMultiOrders($orderId, $fakeOrderFromExchange, $orderSide, $resultOrderType);
        } elseif (ExchangeOrderStatus::Expired == $orderStatus) {
            $fakeOrderFromExchange['error'] = "Expired";
            $setPosition = $this->checkIfThereIsRemainingMultiOrders($orderId, $fakeOrderFromExchange, $orderSide, 'expired');
            //$setPosition = $this->newPositionCCXT->manageExpiredOrder($this->position, $orderFromExchange);
            $this->Monolog->sendEntry('debug', "Order $order->orderId was canceled according to the order type\'s rules");
            $this->sendNotification(
                'checkFirstBuyError',
                'Order was canceled according to the order type\'s rules',
                ['orderId' => $order->orderId, 'orderType' => $order->type]
            );
        } elseif ($orderStatus == 'closed') {
            $setPosition = $this->getPositionSettingsForEntry($orderFromExchange);
            if ($setPosition) {
                $this->sendNotification('checkFirstBuySuccess', false);
            } else {
                $this->Monolog->sendEntry('info', "Position with entry order closed without settings (trades?)");
            }
        //} else {
            //$this->Monolog->sendEntry('info', "Order not filled yet");
        }

        return !empty($setPosition) ? $setPosition : false;
    }

    /**
     * Return the array of the resulting position for the given error.
     * @param string $orderId
     * @param array $orderFromExchange
     * @param string $side
     * @param string $type
     * @return array
     */
    public function checkIfThereIsRemainingMultiOrders(string $orderId, array $orderFromExchange, string $side, string $type)
    {
        global $Status;

        if ('MULTI' === $this->position->buyType) {
            foreach ($this->position->orders as $order) {
                if ($order->orderId === $orderId) {
                    continue;
                }

                if (!empty($order->side) && !empty($order->originalEntry) && empty($order->done)) {
                    $multiDataField = 'sell' === $side ? 'multiSecondData' : 'multiFirstData';
                    return [
                        "orders.$orderId.status" => $type,
                        "orders.$orderId.done" => true,
                        "$multiDataField.error" => $orderFromExchange['error'],
                    ];
                }
            }
        }

        $this->Monolog->sendEntry('warning', "Closing position", $orderFromExchange);
        if ('error' === $type) {
            $this->sendNotification('checkFirstBuyError', $orderFromExchange['error']); //Todo: Review the notification message.
            return [
                "orders.$orderId.status" => $type,
                "orders.$orderId.done" => true,
                'closed' => true,
                'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                'status' => $Status->getPositionStatusFromError($orderFromExchange['error']),
                'error' => $orderFromExchange['error'],
            ];
        } else {
            $this->sendNotification('checkFirstBuyError', false, ['trigger' => 'checkBuyOrders']); //Todo: Review the notification message.
            return [
                "orders.$orderId.status" => $type,
                "orders.$orderId.done" => true,
                'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                "status" => ExchangeOrderStatus::Expired == $type ? 105 : 11,
                "closed" => true,
            ];
        }
    }

    /**
     * Cancel the entry order and return the updated data for the position.
     *
     * @param string $orderId
     * @param int $status
     * @param bool $remainingEntryOrders
     * @return array|bool
     */
    private function cancelEntryOrder(string $orderId, int $status, bool $remainingEntryOrders = false)
    {
        $orderFromExchange = $this->ExchangeCalls->exchangeCancelOrder($orderId, $this->position);
        $orderStatus = is_object($orderFromExchange) ? $orderFromExchange->getStatus() : false;
        if (!$orderStatus) {
            $error = is_array($orderFromExchange) ? $orderFromExchange : [];
            $this->Monolog->sendEntry('error', "Cancel position failed", $error);
        } else {
            if ($orderFromExchange->getFilled() > 0) {
                $setPosition = $this->getPositionSettingsForEntry($orderFromExchange);
                $this->sendNotification('checkFirstBuyFilled', "Partially Filled");
                return $setPosition;
            } else {
                if ($remainingEntryOrders) {
                    $setPosition = [
                        "orders.$orderId.done" => true,
                        "orders.$orderId.status" => $orderFromExchange->getStatus(),
                    ];
                    $this->newPositionCCXT->setPosition($this->position->_id, $setPosition);
                    return false;
                } else {
                    $setPosition = [
                        "orders.$orderId.done" => true,
                        "orders.$orderId.status" => $orderFromExchange->getStatus(),
                        "closed" => true,
                        'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                        "status" => $status
                    ];
                }
            }
        }

        return !empty($setPosition) ? $setPosition : false;
    }

    private function checkReEntryOrder($order)
    {
        global $Exchange;

        $orderId = $order->orderId;
        $reEntryTargetId = $this->newPositionCCXT->getCurrentReEntryTargetId($this->position, $orderId);

        $this->Monolog->sendEntry('info', "OrderId: $orderId, ReBuyTargetId: $reEntryTargetId");

        if ($reEntryTargetId) {
            $orderFromExchange = $this->ExchangeCalls->getOrder($this->position, $orderId);
            if (!is_object($orderFromExchange) && isset($orderFromExchange['error'])) {
                if ($this->skipError($orderFromExchange['error'], $reEntryTargetId)) {
                    $method = $Exchange->getLogMethodFromError($orderFromExchange['error']);
                    $this->Monolog->sendEntry($method, "Skipping check for position", $orderFromExchange);
                    $skippingErrors = isset($this->position->reBuyTargets->$reEntryTargetId->skippingErrors) ?
                        $this->position->reBuyTargets->$reEntryTargetId->skippingErrors + 1 : 1;
                    $setPosition = [
                        "reBuyTargets.$reEntryTargetId.skippingErrors" => $skippingErrors,
                        "reBuyTargets.$reEntryTargetId.updated" => new \MongoDB\BSON\UTCDateTime(),
                    ];
                } else {
                    $this->Monolog->sendEntry('error', "Error retrieving Order" . $orderFromExchange['error']);
                    $setPosition = [
                        "reBuyProcess" => true,
                        'increasingPositionSize' => false,
                        "orders.$orderId.status" => 'ERROR',
                        "orders.$orderId.done" => true,
                        "reBuyTargets.$reEntryTargetId.error" => $orderFromExchange['error'],
                        "reBuyTargets.$reEntryTargetId.skipped" => true,
                        "reBuyTargets.$reEntryTargetId.done" => true,
                        "reBuyTargets.$reEntryTargetId.updated" => new \MongoDB\BSON\UTCDateTime(),
                    ];
                    $this->sendNotification(
                        'checkDCAFilledError',
                        $orderFromExchange['error'],
                        ['reBuyTargetId' => $reEntryTargetId, 'orderId' => $orderId]
                    );
                }
            } elseif ($orderFromExchange->getStatus() == 'cancelled' || $orderFromExchange->getStatus() == 'canceled') {
                $this->Monolog->sendEntry('debug', "ReBuyTarget $reEntryTargetId was canceled");
                $setPosition = [
                    "reBuyProcess" => true,
                    "increasingPositionSize" => false,
                    "reBuyTargets.$reEntryTargetId.skipped" => true,
                    "reBuyTargets.$reEntryTargetId.done" => true,
                    "reBuyTargets.$reEntryTargetId.updated" => new \MongoDB\BSON\UTCDateTime(),
                    "orders.$orderId.status" => $orderFromExchange->getStatus(),
                    "orders.$orderId.done" => true,
                ];
            } elseif (ExchangeOrderStatus::Expired == $orderFromExchange->getStatus()) {
                $setPosition = $this->newPositionCCXT->manageExpiredOrder($this->position, $orderFromExchange);
                $this->Monolog->sendEntry('debug', "Order $orderFromExchange->orderId was canceled according to the order type\'s rules");
                $this->sendNotification(
                    'checkDCAFilledError',
                    'Order was canceled according to the order type\'s rules',
                    ['orderId' => $orderFromExchange->orderId, 'orderType' => $orderFromExchange->type]
                );
            } elseif ($orderFromExchange->getStatus() == 'closed') {
                $this->Monolog->sendEntry('debug', "ReEntryTarget $reEntryTargetId successfully filled.");
                $setPosition = $this->getPositionSettingsForReEntry($orderFromExchange, $reEntryTargetId);
                $this->sendNotification(
                    'checkDCAFilledSuccess',
                    false,
                    ['reBuyTargetId' => $reEntryTargetId, 'orderId' => $orderId]
                );
            //} else {
            //    $this->Monolog->sendEntry('info', "Order $orderId not filled yet");
            }
        }

        return isset($setPosition) ? $setPosition : false;
    }

    /**
     * Given a given order, checks if needs to be reviewed and update the position if needed.
     *
     * @param object $order
     * @param bool $beforeReEntry
     * @return array|bool
     */
    private function checkExitOpenOrders(object $order, bool $beforeReEntry)
    {
        if (!$this->checkIfOrderNeedReview($order, $beforeReEntry)) {
            return false;
        }

        $orderId = $order->orderId;
        $orderFromExchange = $this->ExchangeCalls->getOrder($this->position, $orderId);
        $setPosition = [];

        if (is_object($orderFromExchange)) {
            $orderStatus = $orderFromExchange->getStatus();
            $this->Monolog->sendEntry('info', "Checking exit order $orderId (Status: $orderStatus)");
            if ($orderStatus == 'closed') {
                $this->Monolog->sendEntry('debug', "Filled");

                $trades = $this->ExchangeCalls->getTrades($this->position, false, $orderFromExchange);
                if (!empty($trades)) {
                    $this->position = $this->newPositionCCXT->pushDataAndReturnDocument($this->position->_id, 'trades', $trades);
                } else {
                    $this->Monolog->sendEntry('debug', "No trades found, anyway, amount is: ". $orderFromExchange->getAmount());
                    return false;
                }

                $this->position = $this->newPositionCCXT->updateOrderAndCheckIfPositionNeedToBeClosed(
                    $this->position,
                    $orderFromExchange->getId(),
                    (float)$orderFromExchange->getPrice(),
                    $orderFromExchange->getStatus()
                );
                if ($order->type == 'takeProfit') {
                    $this->sendNotification('checkSellOpenOrdersSuccess', false, ['orderId' => $orderId, 'orderType' => $order->type]);
                    if ($this->newPositionCCXT->checkIfTakeProfitWasTheLastOneAndThereIsRemainingAmount($this->Accounting, $this->position) && empty($this->position->skipExitingAfterTP)) {
                        $this->Monolog->sendEntry('info', 'Selling remaining amount');
                        $message = json_encode([
                            'positionId' => $this->position->_id->__toString(),
                            'status' => 17
                        ], JSON_PRESERVE_ZERO_FRACTION);
                        $this->RabbitMQ = new RabbitMQ();
                        $queueName = 'stopLoss';
                        $this->RabbitMQ->publishMsg($queueName, $message);
                    } elseif ($this->position->stopLossFollowsTakeProfit || $this->position->stopLossToBreakEven) {
                        $this->sendStopOrders();
                    }
                }

                if ($order->type == 'exit') {
                    $this->sendNotification('checkSellOpenOrdersSuccess', false, ['orderId' => $orderId, 'orderType' => $order->type]);
                }

                if ($order->type == 'stopLoss' && $this->positionMediator->getExchangeType() == 'futures' && isset($order->originalAmount)) {
                    if ((float)$order->originalAmount > $orderFromExchange->getAmount()) {
                        $this->Monolog->sendEntry('error', "The contract was reduced from another source.)");
                        $setPosition = [
                            'closed' => true,
                            'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                            'status' => 91,
                            'lastUpdate' => new \MongoDB\BSON\UTCDateTime(),
                        ];
                        $msgError = 'The stop loss tried to sell '.$order->originalAmount.' but only '.$orderFromExchange->getAmount() .' were left in the contract. The contract was reduced outside this position, so we are closing it.';
                        $this->sendNotification('checkSellOpenOrdersError', $msgError, ['orderId' => $order->orderId,
                            'orderType' => $order->type]);
                    }
                }
            } elseif ($orderStatus == 'cancelled' || $orderStatus == 'canceled') {
                $this->Monolog->sendEntry('debug', "Closing position because this order was canceled by the user");
                $setPosition = [
                    'closed' => true,
                    'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                    'status' => 11,
                    'lastUpdate' => new \MongoDB\BSON\UTCDateTime(),
                ];
            } elseif ($orderStatus == ExchangeOrderStatus::Expired) {
                $setPosition = array_merge(
                    $setPosition,
                    $this->newPositionCCXT->manageExpiredOrder($this->position, $orderFromExchange)
                );

                $this->Monolog->sendEntry('debug', "Order $order->orderId was canceled according to the order type\'s rules");
                $this->sendNotification(
                    'checkSellOpenOrdersError',
                    'Order was canceled according to the order type\'s rules',
                    ['orderId' => $order->orderId, 'orderType' => $order->type]
                );
            }
        } else {
            $this->Monolog->sendEntry('info', "Checking exit order $orderId with error: " . $orderFromExchange['error']);
            $this->sendAlert($order, $orderFromExchange);
            if ($this->checkIfOrderNeedToBeClosedBecauseOfError($orderFromExchange)) {
                $setPosition = [
                    'closed' => true,
                    'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                    'status' => 32,
                    'lastUpdate' => new \MongoDB\BSON\UTCDateTime(),
                ];
                $msgError = isset($orderFromExchange['error']) ? $orderFromExchange['error'] : false;
                $this->sendNotification('checkSellOpenOrdersError', $msgError, ['orderId' => $order->orderId,
                    'orderType' => $order->type]);
            } else if ($order->type == 'takeProfit' && $this->checkIfOrderNeedToReSendTakeProfitsBecauseOfError($orderFromExchange)) {
                $this->Monolog->sendEntry('warning', "Re Sending Take Profits because last doesn't exists");
                $this->sendTakeProfits();
            }
        }

        return isset($setPosition) ? $setPosition : false;
    }

    /**
     * Checks if an exit order needs to be reviewed.
     *
     * @param object $order
     * @param bool $beforeReEntry
     * @return bool
     */
    private function checkIfOrderNeedReview(object $order, bool $beforeReEntry)
    {
        if ($order->status == 'cancelled') {
            return false;
        }

        if ($order->done && $order->cost > 0) {
            return false;
        }

        if ($beforeReEntry) {
            return true;
        }

        if ($this->forceCheck) {
            return true;
        }

        if ($order->type == 'buy' || $order->type == 'entry') {
            return false;
        }

        if ($order->type == 'stopLoss') {
            return true;
        }

        if ($order->type == 'takeProfit') {
            return $this->checkIfTakeProfitOrderNeedToBeChecked($order);
        }

        if ($this->checkOrdersByTime) {
            return true;
        }

        return false;
    }

    private function checkIfOrderNeedToBeClosedBecauseOfError($order)
    {
        global $newUser;

        if (!isset($order['error']))
            return false;

        $closingErrors = [
            'Invalid API-key, IP, or permissions for action',
            'Signature for this request is not valid',
            'Invalid API key/secret pair'
        ];

        $error = $order['error'];
        foreach ($closingErrors as $closingError)
            if (strpos(strtolower($error), strtolower($closingError)) !== false) {
                $user = $newUser->getUser($this->position->user->_id);
                if (!isset($user->exchanges))
                    return true;

                foreach ($user->exchanges as $tmpExchange)
                    if ($tmpExchange->internalId == $this->position->exchange->internalId)
                        $exchange = $tmpExchange;

                if (isset($exchange) && !$exchange->areKeysValid) {
                    global $Notification;

                    $this->Monolog->sendEntry('debug', "Closing position because invalid keys");

                    $domain = $user->projectId == 'ct01' ? 'app.altexample.com' : 'example.net';
                    $positionUrl = 'https://' . $domain . '/app/position/' . $this->position->_id->__toString();
                    $headMessage = "*ERROR:* Invalid key/secret pair \n";
                    $endingMessage = "The position [$positionUrl]($positionUrl) has been closed.";
                    $message = $headMessage . $endingMessage;
                    $Notification->sendPositionUpdateNotification($user, $message);

                    return true;
                }
            }

        return false;
    }

    private function checkIfOrderNeedToReSendTakeProfitsBecauseOfError($order)
    {
        if (!isset($order['error']))
            return false;

        $closingErrors = [
            'Order does not exist',
            'Not valid order status from ccxt expired',
        ];

        foreach ($closingErrors as $closingError)
            if (stripos($order['error'], $closingError) !== false)
                return true;

        return false;
    }


    /**
     * Given a take profit order, return if it needs to be checked based on time or if the last check was older
     * than one hour ago.
     *
     * @param object $order
     * @return bool
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function checkIfTakeProfitOrderNeedToBeChecked(object $order)
    {
        //$this->Monolog->sendEntry('debug', "checking OpenOrdersLastGlobalCheck: " . $this->position->checkingOpenOrdersLastGlobalCheck->__toString() . " time: " . time());
        if ($this->position->checkingOpenOrdersLastGlobalCheck->__toString() / 1000 + 3600 < time()) {
            $setPosition = [
                'checkingOpenOrdersLastGlobalCheck' => new \MongoDB\BSON\UTCDateTime(),
            ];
            $this->position = $this->newPositionCCXT->setPosition($this->position->_id, $setPosition, false);
            $this->checkOrdersByTime = true;

            return true;
        }

        $extremePrice = $this->positionMediator->getRecentHigherPrice($this->getSince());

        if ($this->positionMediator->isLong() && $extremePrice >= $order->price) {
            $this->Monolog->sendEntry('debug', "LONG: Symbol recent extreme price: $extremePrice, order price: {$order->price} since {$this->getSince()}");
            return true;
        }

        if ($this->positionMediator->isShort() && $extremePrice <= $order->price) {
            $this->Monolog->sendEntry('debug', "SHORT: Symbol recent extreme price: $extremePrice, order price: {$order->price} since {$this->getSince()}");
            return true;
        }

        return false;
    }

    /**
     * Gets a closed order from an entry and updates the position with its data if no errors.
     *
     * @param ExchangeOrder $order
     * @return array|bool
     */
    private function getPositionSettingsForEntry(ExchangeOrder $order)
    {
        global $Accounting;

        $orderId = $order->getId();
        $trades = $this->ExchangeCalls->getTrades($this->position, false, $order);
        $orderPrice = number_format($order->getPrice(), 12, '.', '');
        $orderStatus = $order->getStatus();

        if (!empty($trades)) {
            $this->position = $this->newPositionCCXT->pushDataAndReturnDocument($this->position->_id, 'trades', $trades);
        } else {
            $method = 'kucoin' === strtolower($this->position->exchange->name) ? 'debug' : 'debug';
            $this->Monolog->sendEntry($method, "No trades found, anyway, amount is: ". $order->getAmount());
            return false;
        }

        $realEntryPrice = (float)($orderPrice);
        $realEntryPriceString = is_object($realEntryPrice) ? (float)$realEntryPrice->__toString() : (float)$realEntryPrice;
        $realAmount = $Accounting->getRealAmount($this->position, $orderId);

        /*$exchangeHandler = $this->positionMediator->getExchangeMediator()->getExchangeHandler();
        $zigId = $this->positionMediator->getSymbol();

        $contractAmount = $this->getContractAmount();
        if ($contractAmount !== false) {
            $amountDifference = $realAmount - $contractAmount;
            if ($amountDifference > 0) {
                $fakeTrades = [];
                $tradePrice = $realEntryPriceString;
                $tradeAmount = $amountDifference * -1;
                $tradeCost = $exchangeHandler->calculateOrderCostZignalyPair(
                    $zigId,
                    $tradeAmount,
                    $tradePrice
                );
                $fakeTrades[] = [
                    "symbol" => $this->positionMediator->getSymbolWithSlash(), //$this->position->signal->base.'/'.$this->position->signal->quote,
                    "id" => "RE_".time(),
                    "orderId" => $order->getId(),
                    "orderListId" => -1,
                    "price" => $tradePrice,
                    "qty" => $tradeAmount,
                    "cost" => $tradeCost,
                    "quoteQty" => 0,
                    "commission" => 0,
                    "commissionAsset" => 'BNB',
                    "time" => time(),
                    "isBuyer" => 'buy' === $order->getSide(),
                    "isMaker" => false,
                    "isBestMatch" => null,
                    "fakeTradeForSyncingContract" => true,
                ];
                $this->position = $this->newPositionCCXT->pushDataAndReturnDocument($this->position->_id, 'trades', $fakeTrades);
                $realAmount = $Accounting->getRealAmount($this->position, $orderId);
                //Todo: Send notification for reduction.
            }
        }*/

        $symbol = $this->positionMediator->getSymbol();
        $minNotionalOk = $this->ExchangeCalls->checkIfValueIsGood('amount', 'min', $realAmount, $symbol);
        $closed = $minNotionalOk ? false : true;
        $status = $closed ? 73 : 9;

        $exchangeHandler = $this->positionMediator
            ->getExchangeMediator()->getExchangeHandler();

        $realPositionSize = number_format(
            $exchangeHandler->calculatePositionSize(
                $symbol,
                $realAmount,
                $realEntryPriceString
            ),
            12,
            '.',
            ''
        );

        $leverage = isset($this->position->leverage) && $this->position->leverage > 0? $this->position->leverage: 1;

        $this->position->leverage = $leverage;

        $realInvestment = number_format(
            $realPositionSize / $this->position->leverage,
            12,
            '.',
            ''
        );
        
        $setPosition = [
            "realAmount" => (float)($realAmount),
            "remainAmount" => (float)($realAmount),
            "realPositionSize" => (float)($realPositionSize),
            "origBuyPrice" => $realEntryPrice,
            "realBuyPrice" => $realEntryPrice,
            "avgBuyingPrice" => $realEntryPrice,
            "orders.$orderId.price" => $realEntryPrice,
            "orders.$orderId.amount" => $realAmount,
            "orders.$orderId.cost" => $exchangeHandler
                ->calculateOrderCostZignalyPair($symbol, $realAmount, $orderPrice),
            "orders.$orderId.status" => $orderStatus,
            "orders.$orderId.done" => true,
            "buyPerformed" => true,
            "buyPerformedAt" => new \MongoDB\BSON\UTCDateTime(),
            'stopLossPercentageLastUpdate' => new \MongoDB\BSON\UTCDateTime(),
            "trailingStopLastUpdate" => new \MongoDB\BSON\UTCDateTime(),
            "status" => $status,
            "closed" => $closed,
            "updating" => true,
            'lastUpdatingAt' => new \MongoDB\BSON\UTCDateTime(),
            'reBuyProcess' => true,
            'realInvestment' => (float)($realInvestment),
        ];

        $setFromFlipPosition = $this->newPositionCCXT->flipPosition($this->position, $orderId);

        return array_merge($setPosition, $setFromFlipPosition);
    }

    /**
     * Retrieve user's exchange contracts and retrieve the contract amount for the position's symbol.
     *
     * @return bool|float|int
     */
    private function getContractAmount()
    {
        if ($this->positionMediator->getExchangeType() !== 'futures') {
            return false;
        }

        $positionUserId = empty($this->position->profitSharingData) ? $this->position->user->_id : $this->position->profitSharingData->exchangeData->userId;
        $positionExchangeInternalId = empty($this->position->profitSharingData) ? $this->position->exchange->internalId : $this->position->profitSharingData->exchangeData->internalId;

        $contracts = $this->ExchangeCalls->getContracts($positionUserId, $positionExchangeInternalId);
        if (empty($contracts)) {
            return false;
        }

        if (isset($contracts['error'])) {
            $this->Monolog->sendEntry('error', 'Error retrieving contracts', $contracts);
            return false;
        }

        $marketEncoder = $this->positionMediator
            ->getExchangeMediator()->getMarketEncoder();
        foreach ($contracts as $contract) {
            try {
                $zigId = $marketEncoder->fromCcxt($contract->getSymbol());
                if ($zigId == $this->position->signal->pair) {
                    $contractSide = strtolower($contract->getSide());
                    $positionSide = strtolower($this->position->side);
                    if ($contractSide == 'both' || $contractSide == $positionSide) {
                        return abs($contract->getAmount());
                    }
                }
            } catch (\Exception $ex){
                // catching exception here?!?
                $this->Monolog->sendEntry('error', 'Error retrieving zignaly id from ccxt symbol', $contract->getSymbol());
            }
        }

        return false;
    }

    /**
     * Gets a closed order from a reEntry and updates the position with its data if no errors.
     * @param ExchangeOrder $order
     * @param $reEntryTargetId
     * @return array
     */
    private function getPositionSettingsForReEntry(ExchangeOrder $order, $reEntryTargetId)
    {
        global $Accounting;

        $orderId = $order->getId();
        $trades = $this->ExchangeCalls->getTrades($this->position, false, $order);
        if (!empty($trades)) {
            $this->position = $this->newPositionCCXT->pushDataAndReturnDocument($this->position->_id, 'trades', $trades);
        } else {
            $this->Monolog->sendEntry('debug', "No trades found, anyway, amount is: ". $order->getAmount());
            return false;
        }

        $orderPrice = number_format($order->getPrice(), 12, '.', '');
        $tradesSideType = isset($this->position->side) && strtolower($this->position->side) == 'short' ? 'sell' : 'buy';
        $avgPrice = $Accounting->getAveragePrice($this->position, $tradesSideType);
        list($totalAmount, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($this->position);

        $exchangeHandler = $this->positionMediator->getExchangeMediator()->getExchangeHandler();

        $amount = $Accounting->getRealAmount($this->position, $orderId);
        // LFERN $realPositionSize = number_format($avgPrice * $totalAmount, 12,
        // LFERN     '.', '');
        $realPositionSize = number_format(
            $exchangeHandler->calculatePositionSize(
                $this->positionMediator->getSymbol(),
                $totalAmount,
                $avgPrice
            ),
            12,
            '.',
            ''
        );

        $leverage = isset($this->position->leverage) && $this->position->leverage > 0? $this->position->leverage: 1;
        $this->position->leverage = $leverage;

        $realInvestment = number_format($realPositionSize / $this->position->leverage, 12, '.', '');

        return [
            "orders.$orderId.status" => $order->getStatus(),
            "orders.$orderId.done" => true,
            "orders.$orderId.price" => $orderPrice,
            "orders.$orderId.amount" => $amount,
            // LFERN "orders.$orderId.cost" => $orderPrice * $amount,
            "orders.$orderId.cost" => $exchangeHandler
                ->calculateOrderCostZignalyPair(
                    $this->positionMediator->getSymbol(),
                    $amount,
                    $orderPrice
                ),
            "reBuyTargets.$reEntryTargetId.done" => true,
            "reBuyTargets.$reEntryTargetId.updated" => new \MongoDB\BSON\UTCDateTime(),
            "increasingPositionSize" => false,
            "realAmount" => (float)($totalAmount),
            "remainAmount" => (float)($remainAmount),
            "realPositionSize" => (float)($avgPrice * $totalAmount),
            "realBuyPrice" => (float)($avgPrice),
            "avgBuyingPrice" => (float)($avgPrice),
            'stopLossPercentageLastUpdate' => new \MongoDB\BSON\UTCDateTime(),
            "trailingStopLastUpdate" => new \MongoDB\BSON\UTCDateTime(),
            "trailingStopPrice" => false,
            "updating" => true,
            'lastUpdatingAt' => new \MongoDB\BSON\UTCDateTime(),
            'reBuyProcess' => true,
            'realInvestment' => (float)($realInvestment),
        ];
    }

    private function getSince()
    {
        if (isset($this->position->buyPerformedAt) && $this->position->buyPerformedAt > $this->position->lastUpdate)
            return $this->position->buyPerformedAt;

        return $this->position->lastUpdate;
    }

    private function sendAlert($order, $orderFromExchange)
    {
        global $Exchange;
        
        if (!isset($orderFromExchange['error']))
            $orderFromExchange['error'] = '';
        
        $method = !isset($orderFromExchange['error']) ? 'debug'
            : $Exchange->getLogMethodFromError($orderFromExchange['error']);

        $this->Monolog->sendEntry($method, "Error getting order " . $order->orderId ." : " . $orderFromExchange['error']);
    }

    private function sendCopyTradingSignal()
    {
        if (empty($this->position->provider->profitSharing)) {
            if (isset($this->position->signal->masterCopyTrader) && $this->position->signal->masterCopyTrader
                && !$this->newPositionCCXT->checkIfDCAHasBeenDone($this->position)) {
                $message = json_encode([
                    'positionId' => $this->position->_id->__toString(),
                    'origin' => 'copyTrading',
                    'type' => 'buyFromFollowers'
                ], JSON_PRESERVE_ZERO_FRACTION);
                $this->RabbitMQ = new RabbitMQ();
                $this->RabbitMQ->publishMsg('signals', $message);
            }
        }
    }

    private function sendNotification($command, $error = false, $extraParameters = false)
    {
        $parameters = [
            'userId' => $this->position->user->_id->__toString(),
            'positionId' => $this->position->_id->__toString(),
            'status' => $this->position->status,
        ];

        if ($error)
            $parameters['error'] = $error;

        if ($extraParameters and is_array($extraParameters)) {
            foreach ($extraParameters as $key => $value) {
                $parameters[$key] = $value;
            }
        }

        $message = [
            'command' => $command,
            'chatId' => false,
            'code' => false,
            'parameters' => $parameters
        ];

        $message = json_encode($message, JSON_PRESERVE_ZERO_FRACTION);
        $this->RabbitMQ = new RabbitMQ();
        $this->RabbitMQ->publishMsg('profileNotifications', $message);
    }

    private function sendTakeProfits()
    {
        if ($this->position && is_object($this->position->_id)) {
            $this->Monolog->sendEntry('debug', "Sending take profit message");
            $message = json_encode(['positionId' => $this->position->_id->__toString()], JSON_PRESERVE_ZERO_FRACTION);
            $this->RabbitMQ = new RabbitMQ();
            $queueName = 'takeProfit';
            $this->RabbitMQ->publishMsg($queueName, $message);
        }
    }

    /**
     * Check if the position has any reduce order and send it to the queue if any.
     */
    private function sendReduceOrder(): void
    {
        if (!empty($this->position->reduceOrders)) {
            $message = json_encode(['positionId' => $this->position->_id->__toString()], JSON_PRESERVE_ZERO_FRACTION);
            $queueName = 'reduceOrdersQueue';
            $this->RedisHandlerZignalyQueue->addSortedSet($queueName, time(), $message, true);
        }
    }

    /**
     * Check if the position has stop loss and send it to the queue if any.
     */
    private function sendStopOrders(): void
    {
        if (!empty($this->position->stopLossPercentage) || !empty($this->position->stopLossPrice)) {
            $message = json_encode(['positionId' => $this->position->_id->__toString()], JSON_PRESERVE_ZERO_FRACTION);
            $queueName = 'stopOrdersQueue';
            $this->RedisHandlerZignalyQueue->addSortedSet($queueName, time(), $message, true);
        }
    }

    private function skipError($error, $reBuyTargetId = false)
    {
        if ($reBuyTargetId && isset($this->position->reBuyTargets->$reBuyTargetId->skippingErrors) && $this->position->reBuyTargets->$reBuyTargetId->skippingErrors > 3)
            return false;

        $skippingErrors = [
            'Timestamp for this request is outside of the recvWindow',
            'Order does not exist',
            'Too many requests',
            '504 Gateway Time-out',
            'Invalid API key/secret pair',
            'unknown error',
            'startTime',
            'Please try again',
            'Operation timed out',
            'Try again later',
            'https'
        ];

        foreach ($skippingErrors as $skippingError)
            if (strpos(strtolower($error), strtolower($skippingError)) !== false)
                return true;

        return false;
    }
}