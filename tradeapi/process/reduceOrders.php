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


use \MongoDB\Model\BSONDocument;
use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangeOrder;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use Zignaly\utils\PositionUtils;

require_once __DIR__ . '/../loader.php';
global $continueLoop, $RestartWorker;

$processName = 'reduceOrders';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
/** @var newPositionCCXT $newPositionCCXT */
$newPositionCCXT = $container->get('newPositionCCXT.model');
$newPositionCCXT->configureLoggingByContainer($container);
$RedisHandlerZignalyQueue = $container->get('redis.queue');
$ExchangeCalls = new ExchangeCalls($Monolog);
$lastPriceService = $container->get('lastPrice');
$newPositionCCXT->configureExchangeCalls($ExchangeCalls);
$newPositionCCXT->configureLastPriceService($lastPriceService);
$Accounting = $container->get('accounting');
$RedisLockController = $container->get('RedisLockController');
$scriptStartTime = time();
$queueName = (isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : 'reduceOrdersQueue';
$positionId = (isset($argv['2'])) && $argv['2'] != 'false' ? $argv['2'] : false;

do {
    $workingAt = time();
    $inQueue = false;
    $continueProcess = true;
    $reQueue = false;
    try {
        $Monolog->trackSequence();
        $Monolog->addExtendedKeys('queueName', $queueName);

        $RestartWorker->checkProcessStatus($processName, $scriptStartTime, $Monolog, 120);
        list($position, $inQueueAt) = getPosition($RedisLockController, $RedisHandlerZignalyQueue, $Monolog, $processName, $positionId, $queueName);
        if (!$position) {
            if (!$positionId) {
                sendReduceOrderBackToQueue($RedisHandlerZignalyQueue, $positionId, $queueName);
            }
            continue;
        }

        if (empty($position->reduceOrders)) {
            $continueProcess = false;
        }

        if ($continueProcess) {
            $positionMediator = PositionMediator::fromMongoPosition($position);
            $exchangeAccountType = $positionMediator->getExchangeType();
            $isTestnet = $positionMediator->getExchangeIsTestnet();

            if (!$ExchangeCalls->setCurrentExchange($positionMediator->getExchange()->getId(), $exchangeAccountType, $isTestnet)) {
                $Monolog->sendEntry('critical', 'Error connecting the exchange');
                $continueProcess = false;
                $reQueue = true;
            }
        }

        if ($continueProcess) {
            if ($newPositionCCXT->cancelPendingOrders($position, ['takeProfit'])) {
                $position = $newPositionCCXT->getPosition($position->_id);
                $positionMediator->updatePositionEntity($position);
                sendReduceOrder($newPositionCCXT, $Accounting, $ExchangeCalls, $position, $positionMediator, $Monolog, $RedisHandlerZignalyQueue);
            } else {
                $Monolog->sendEntry('error', 'Error canceling take profits orders');
                $reQueue = true;
            }
        }

        $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
        if ($reQueue) {
            sendReduceOrderBackToQueue($RedisHandlerZignalyQueue, $position->_id->__toString(), $queueName);
        }
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Unknown error: " . $e->getMessage());
        continue;
    }
} while ($continueLoop && !$positionId);

/**
 * Send the reduce order if amount and price are good.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param Accounting $Accounting
 * @param ExchangeCalls $ExchangeCalls
 * @param BSONDocument $position
 * @param PositionMediator $positionMediator
 * @param Monolog $Monolog
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @return array|bool|\Zignaly\exchange\ExchangeOrder
 */
function sendReduceOrder(
    newPositionCCXT $newPositionCCXT,
    Accounting $Accounting,
    ExchangeCalls $ExchangeCalls,
    BSONDocument $position,
    PositionMediator $positionMediator,
    Monolog $Monolog,
    RedisHandler $RedisHandlerZignalyQueue
)
{
    $target = getReduceTarget($position);
    if (empty($target)) {
        return false;
    }

    list($amount, $price, $goodToSend) = getAmountPrice($Accounting, $positionMediator, $position, $ExchangeCalls, $Monolog, $target);
    if (empty($goodToSend)) {
        // $cost = $amount * $price;
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
        $cost = $exchangeHandler->calculateOrderCostZignalyPair(
            $positionMediator->getSymbol(),
            $amount,
            $price
        );

        $order = ['error' => "Amount $amount, Price: $price or Cost: $cost aren't valid."];
        updateOrderWithError($newPositionCCXT, $order, $target->targetId, $position->_id);
        $headMessage = "*ERROR:* The reduce order {$target->targetId} couldn't be placed because Amount $amount, Price: $price or Cost: $cost aren't valid.\n";
        sendNotification($position, $headMessage);
        return false;
    }

    $options = PositionUtils::extractOptionsForOrder($positionMediator, $target);

    $positionUserId = empty($position->profitSharingData) ? $position->user->_id : $position->profitSharingData->exchangeData->userId;
    $positionExchangeInternalId = empty($position->profitSharingData) ? $position->exchange->internalId : $position->profitSharingData->exchangeData->internalId;

    $order = $ExchangeCalls->sendOrder(
        $positionUserId,
        $positionExchangeInternalId,
        $positionMediator->getSymbolWithSlash(),
        $target->type,
        $positionMediator->isShort() ? 'buy' : 'sell',
        $amount,
        $price,
        $options,
        true,
        $position->_id->__toString()
    );

    if (is_object($order)) {
        updateSuccessfulOrder($newPositionCCXT, $order, $target, $amount, $position);
        $headMessage = "*NEW:* The reduce order {$target->targetId} placed.\n";
        /*if ($order->getType() == 'market') {
            $quickPriceWatcherQueueName = empty($position->testNet) && empty($position->paperTrading) ? 'quickPriceWatcher' : 'quickPriceWatcher_Demo';
            $RedisHandlerZignalyQueue->addSortedSet($quickPriceWatcherQueueName, time(), $position->_id->__toString());
        }*/
    } else {
        updateOrderWithError($newPositionCCXT, $order, $target->targetId, $position->_id);
        $headMessage = "*ERROR:* The reduce order {$target->targetId} couldn't be placed.\n";
    }
    sendNotification($position, $headMessage);
}

/**
 * Send a notification for a given position.
 *
 * @param BSONDocument $position
 * @param string $headMessage
 */
function sendNotification(BSONDocument $position, string $headMessage)
{
    global $newUser, $Notification;

    $positionUrl = 'https://example.net/app/position/' . $position->_id->__toString();
    $endingMessage = "Position [$positionUrl]($positionUrl)";
    $user = $newUser->getUser($position->user->_id);
    $message = $headMessage . $endingMessage;
    $Notification->sendPositionUpdateNotification($user, $message);
}

/**
 * From a ExchangeOrder update the position orders and also the reduce order target.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param ExchangeOrder $order
 * @param object $target
 * @param float $amount
 * @param BSONDocument $position
 * @return bool|\MongoDB\BSON\ObjectId
 */
function updateSuccessfulOrder(newPositionCCXT $newPositionCCXT, ExchangeOrder $order, object $target, float $amount, BSONDocument $position)
{
    $newRecurringTarget = getNewReduceOrderTargetIfIsRecurring($position, $target);
    if (!$newRecurringTarget) {
        $targetId = $target->targetId;
    } else {
        $newRecurringTarget['orderId'] = $order->getId();
    }

    $orderData = [
        'orderId' => $order->getId(),
        'status' => $order->getStatus(),
        'type' => 'exit',
        'price' => $order->getPrice(),
        'amount' => $order->getAmount(),
        'originalAmount' => $amount,
        'cost' => $order->getCost(),
        'transacTime' => new \MongoDB\BSON\UTCDateTime($order->getTimestamp()),
        'orderType' => $order->getType(),
        'done' => false,
        'reduceOnly' => $order->getReduceOnly(),
        'clientOrderId' => $order->getRecvClientId(),
    ];

    $setPosition = [
        'orders.' . $order->getId() => $orderData,
    ];

    if (!empty($targetId)) {
        $setPosition['reduceOrders.' . $targetId . '.error'] = false;
        $setPosition['reduceOrders.' . $targetId . '.orderId'] = $order->getId();
    } else {
        $setPosition['reduceOrders.' . $newRecurringTarget['targetId']] = $newRecurringTarget;
    }

    $pushOrder = [
        'order' => [
            '$each' => [$orderData],
        ],
    ];

    return $newPositionCCXT->setPosition($position->_id, $setPosition, true, $pushOrder);
}

/**
 * If the given target is already being used, because is a recurring target, we need to create a new one.
 *
 * @param BSONDocument $position
 * @param object $target
 * @return array|bool
 */
function getNewReduceOrderTargetIfIsRecurring(BSONDocument $position, object $target)
{
    if (empty($target->orderId)) {
        return false;
    }

    $targetsId = [];
    $lastTargetId = 1;
    foreach ($position->reduceOrders as $reduceOrder) {
        $targetsId[] = $reduceOrder->targetId;
        $lastTargetId = $reduceOrder->targetId;
    }

    do {
        $newTargetId = $lastTargetId + 1;
    } while (in_array($newTargetId, $targetsId));

    return [
        "targetId" => $newTargetId,
        "type" => 'limit',
        "targetPercentage" => $target->targetPercentage,
        "availablePercentage" => $target->availablePercentage,
        "recurring" => false,
        "persistent" => false,
        "orderId" => false,
        "done" => false,
        "error" => false
    ];
}

/**
 * Prepare the update for a failed order and update the position.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param $order
 * @param int $targetId
 * @param \MongoDB\BSON\ObjectId $positionId
 * @return bool|\MongoDB\BSON\ObjectId
 */
function updateOrderWithError(newPositionCCXT $newPositionCCXT, $order, int $targetId, \MongoDB\BSON\ObjectId $positionId)
{
    if (!empty($order['error'])) {
        $error = $order['error'];
    } else {
        $error = 'Unknown error';
    }

    $setPosition = [
        'reduceOrders.' . $targetId . '.error' => $error,
        'reduceOrders.' . $targetId . '.done' => true
    ];

    return $newPositionCCXT->setPosition($positionId, $setPosition);
}

/**
 * Get the last undone reduce target or the last recurring one if any.
 *
 * @param BSONDocument $position
 * @return bool|object
 */
function getReduceTarget(BSONDocument $position)
{
    foreach ($position->reduceOrders as $reduceOrder) {
        if (!$reduceOrder->done && !$reduceOrder->orderId) {
            $target = $reduceOrder;
        }

        if ($reduceOrder->recurring) {
            $recurringTarget = $reduceOrder;
        }
    }

    if (empty($target) && !empty($recurringTarget)) {
        $target = $recurringTarget;
    }

    if (empty($target)) {
        return false;
    }

    return $target;
}

/**
 * Get the amount and price for reducing.
 *
 * @param Accounting $Accounting
 * @param PositionMediator $positionMediator
 * @param BSONDocument $position
 * @param ExchangeCalls $ExchangeCalls
 * @param Monolog $Monolog
 * @param object $target
 * @return array
 */
function getAmountPrice(
    Accounting $Accounting,
    PositionMediator $positionMediator,
    BSONDocument $position,
    ExchangeCalls $ExchangeCalls,
    Monolog $Monolog,
    object $target
): array {
    if (!empty($target->amount)) {
        $amountToReduce = $target->amount;
        $price = getPrice($ExchangeCalls, $position, $target->targetId, $positionMediator, $Accounting);
    } else {
        list(, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($position);
        $lockedAmount = $Accounting->getLockedAmountFromPosition($position);

        $availableAmount = $remainAmount - $lockedAmount;
        $price = empty($availableAmount) ? false : getPrice($ExchangeCalls, $position, $target->targetId, $positionMediator, $Accounting);

        if (empty($availableAmount) || empty($price)) {
            $Monolog->sendEntry('warning', "Available amount $availableAmount or price $price are not valid");
            return [false, false, false];
        }

        $amountToReduce = $target->availablePercentage * $availableAmount;
    }

    $amount = checkIfAmountAndPriceAreGood($ExchangeCalls, $Monolog, $amountToReduce, $price, $positionMediator);

    if (!$amount) {
        $Monolog->sendEntry('warning', "Amount to reduce from $amountToReduce is $amount and is not valid");
        return [$amountToReduce, $price, false];
    }

    return [$amount, $price, true];
}

/**
 * Get the target price for a reduce order.
 *
 * @param ExchangeCalls $ExchangeCalls
 * @param BSONDocument $position
 * @param int $targetId
 * @param PositionMediator $positionMediator
 * @param Accounting $Accounting
 * @return bool|float|string
 */
function getPrice(ExchangeCalls $ExchangeCalls, BSONDocument $position, int $targetId, PositionMediator $positionMediator, Accounting $Accounting)
{
    //If the reduce order is type market we use the last price from the market.
    if ('market' === strtolower($position->reduceOrders->$targetId->type)) {
        return $positionMediator->getLastPrice();
    }

    //If the reduce order is not recurring, we use the average entry price:
    if (empty($position->reduceOrders->$targetId->recurring)) {
        if (!empty($position->reduceOrders->$targetId->pricePriority) && 'price' === $position->reduceOrders->$targetId->pricePriority
            && !empty($position->reduceOrders->$targetId->priceTarget)) {
            $price = $position->reduceOrders->$targetId->priceTarget;
        } else {
            $price = $position->reduceOrders->$targetId->targetPercentage * $positionMediator->getAverageEntryPrice();
        }
        return $ExchangeCalls->getPriceToPrecision($price, $positionMediator->getSymbol());
    }

    //If the reduce order is recurring, we use the last entry price:
    $lastEntryPrice = $Accounting->getLastEntryPrice($position);
    if (empty($lastEntryPrice)) {
        return false;
    }
    $price = $position->reduceOrders->$targetId->targetPercentage * $lastEntryPrice;

    return $ExchangeCalls->getPriceToPrecision($price, $positionMediator->getSymbol());
}

/**
 * Check if the given amount/price are good for sending an exit order and return the exact amount if true.
 *
 * @param ExchangeCalls $ExchangeCalls
 * @param Monolog $Monolog
 * @param float $amount
 * @param float $price
 * @param PositionMediator $positionMediator
 * @return bool|float
 */
function checkIfAmountAndPriceAreGood(ExchangeCalls $ExchangeCalls, Monolog $Monolog, float $amount, float $price, PositionMediator $positionMediator)
{
    $amountToReduce = $ExchangeCalls->getAmountToPrecision($amount, $positionMediator->getSymbol());
    $isAmountGood = $ExchangeCalls->checkIfValueIsGood('amount', 'min', $amountToReduce, $positionMediator->getSymbol());
    //$remainingPositionSize = round($amountToReduce * $price, 8);
    $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
    $remainingPositionSize = round($exchangeHandler->calculatePositionSize(
        $positionMediator->getSymbol(),
        $amountToReduce,
        $price
    ), 8);
    $isCostGood = $ExchangeCalls->checkIfValueIsGood('cost', 'min', $remainingPositionSize, $positionMediator->getSymbol());

    if (!$isAmountGood || !$isCostGood) {
        $Monolog->sendEntry('debug', "The amount $amountToReduce with price $price or cost $remainingPositionSize isn't good");
        return false;
    }

    return $amountToReduce;
}

/**
 * Requeue position.
 *
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param string $positionId
 * @param string $queueName
 * @return int
 */
function sendReduceOrderBackToQueue(RedisHandler $RedisHandlerZignalyQueue, string $positionId, string $queueName)
{
    if (empty($positionId)) {
        return 0;
    }

    $message = json_encode(['positionId' => $positionId], JSON_PRESERVE_ZERO_FRACTION);
    return $RedisHandlerZignalyQueue->addSortedSet($queueName, time(), $message, true);
}

/**
 * Get the position from a given id or from the set and lock it.
 *
 * @param RedisLockController $RedisLockController
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param Monolog $Monolog
 * @param string $processName
 * @param bool|string $positionId
 * @param string $queueName
 * @return array
 */
function getPosition(
    RedisLockController $RedisLockController,
    RedisHandler $RedisHandlerZignalyQueue,
    Monolog $Monolog,
    string $processName,
    $positionId,
    string $queueName
)
{
    if (!$positionId) {
        $popMember = $RedisHandlerZignalyQueue->popFromSetOrBlock($queueName);
        if (!empty($popMember)) {
            $positionData = json_decode($popMember[1], true);
            $positionId = $positionData['positionId'];
            $inQueueAt = $popMember[2];
        } else {
            return [false, false];
        }
    } else {
        $inQueueAt = time();
    }

    if ($positionId) {
        $Monolog->addExtendedKeys('positionId', $positionId);
        $position = $RedisLockController->positionHardLock($positionId, $processName);
        if (!$position || $position->closed) {
            $Monolog->sendEntry('debug', "Exiting because lock wasn't acquired.");
            return [false, false];
        }
    }

    return !empty($position) ? [$position, $inQueueAt] : [false, false];
}