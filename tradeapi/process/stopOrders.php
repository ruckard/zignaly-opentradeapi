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
use Zignaly\exchange\ExchangeOrder;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\utils\PositionUtils;

require_once __DIR__ . '/../loader.php';
global $continueLoop;

$processName = 'stopOrders';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
/** @var newPositionCCXT $newPositionCCXT */
$newPositionCCXT = $container->get('newPositionCCXT.model');
$newPositionCCXT->configureLoggingByContainer($container);
/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');
/** @var RedisHandler $RedisHandlerMarketData */
$RedisHandlerMarketData = $container->get('marketData.storage');
/** @var ExchangeCalls $ExchangeCalls */
$ExchangeCalls = $container->get('exchangeMediator');
$lastPriceService = $container->get('lastPrice');
$newPositionCCXT->configureExchangeCalls($ExchangeCalls);
$newPositionCCXT->configureLastPriceService($lastPriceService);
/** @var Accounting $Accounting */
$Accounting = $container->get('accounting');
/** @var RedisLockController $RedisLockController */
$RedisLockController = $container->get('RedisLockController');
$scriptStartTime = time();
$queueName = 'stopOrdersQueue';
$positionId = (isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : false;

do {
    $continueProcess = true;
    $reQueue = false;
    try {
        $Monolog->trackSequence();
        $Monolog->addExtendedKeys('queueName', $queueName);

        list($position, $inQueueAt) = getPosition($RedisLockController, $RedisHandlerZignalyQueue, $Monolog, $processName, $positionId, $queueName);
        if (!$position) {
            if (!$positionId) {
                sendStopOrderBackToQueue($RedisHandlerZignalyQueue, $positionId, $queueName);
            }
            continue;
        }

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeAccountType = $positionMediator->getExchangeType();
        if ('futures' !== $exchangeAccountType) {
            //$Monolog->sendEntry('critical', 'Stop orders only available on futures');
            $continueProcess = false;
        }

        if ($continueProcess && !$ExchangeCalls->setCurrentExchange($positionMediator->getExchange()->getId(), $exchangeAccountType)) {
            $Monolog->sendEntry('critical', 'Error connecting the exchange');
            $continueProcess = false;
            $reQueue = true;
        }

        //Todo: Don't cancel and don't continue if there is and active stop loss and the amount to place is the same.

        if ($continueProcess && !$newPositionCCXT->cancelPendingOrders($position, ['stop'])) {
            $continueProcess = false;
            $Monolog->sendEntry('error', 'Error canceling stop orders');
            $reQueue = true;
        }

        if ($continueProcess && empty($position->stopLossForce) && checkPendingDCAs($position, $positionMediator)) {
            $continueProcess = false;
        }

        if ($continueProcess) {
            $position = $newPositionCCXT->getPosition($position->_id);
            $positionMediator->updatePositionEntity($position);
            sendStopOrder($newPositionCCXT, $Accounting, $ExchangeCalls, $position, $positionMediator, $Monolog);
        }

        $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
        if ($reQueue) {
            sendStopOrderBackToQueue($RedisHandlerZignalyQueue, $position->_id->__toString(), $queueName);
        }
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Unknown error: " . $e->getMessage());
        continue;
    }
} while ($continueLoop && !$positionId);

/**
 * Check if there is any pending entry order.
 * @param BSONDocument $position
 * @param PositionMediator $positionMediator
 * @return bool
 */
function checkPendingDCAs(BSONDocument $position, PositionMediator $positionMediator)
{
    if ('LONG' === $positionMediator->getSide() && $positionMediator->getStopLossPrice() >= $positionMediator->getAverageEntryPrice()) {
        return false;
    }

    if ('SHORT' === $positionMediator->getSide() && $positionMediator->getStopLossPrice() <= $positionMediator->getAverageEntryPrice()) {
        return false;
    }

    foreach ($position->orders as $order) {
        if ('entry' === $order->type && !$order->done) {
            return true;
        }
    }

    return false;
}

/**
 * Send the stop order if amount and price are good.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param Accounting $Accounting
 * @param ExchangeCalls $ExchangeCalls
 * @param BSONDocument $position
 * @param PositionMediator $positionMediator
 * @param Monolog $Monolog
 * @return array|bool|\Zignaly\exchange\ExchangeOrder
 */
function sendStopOrder(
    newPositionCCXT $newPositionCCXT,
    Accounting $Accounting,
    ExchangeCalls $ExchangeCalls,
    BSONDocument $position,
    PositionMediator $positionMediator,
    Monolog $Monolog
) {
    $amounts = getAmounts($Accounting, $position, $ExchangeCalls, $positionMediator);

    $stopPrice = $positionMediator->getStopLossPrice();
    if ($stopPrice <= 0) {
        //$Monolog->sendEntry('debug', 'No stop loss for this position');
        return false;
    }

    if (!$ExchangeCalls->checkIfValueIsGood('price', 'min', $stopPrice, $positionMediator->getSymbol())) {
        $headMessage = "*ERROR:* The stop loss order couldn't be placed because Price: $stopPrice is invalid.\n";
        sendNotification($position, $headMessage);
        return false;
    }

    foreach ($amounts as $amount) {
        if (!$ExchangeCalls->checkIfValueIsGood('market', 'min', $amount, $positionMediator->getSymbol())) {
            $headMessage = "*ERROR:* The stop loss order couldn't be placed because amount: $amount is invalid.\n";
            sendNotification($position, $headMessage);
            return false;
        }

        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
        $cost = $exchangeHandler->calculateOrderCostZignalyPair(
            $positionMediator->getSymbol(),
            $amount,
            $stopPrice
        );
        if (!$ExchangeCalls->checkIfValueIsGood('cost', 'min', $cost, $positionMediator->getSymbol())) {
            $headMessage = "*ERROR:* The stop loss order couldn't be placed because cost: $cost is invalid.\n";
            sendNotification($position, $headMessage);
            return false;
        }

        $options = PositionUtils::extractOptionsForOrder($positionMediator);
        $options['stopLossPrice'] = $stopPrice;

        $positionUserId = empty($position->profitSharingData) ? $position->user->_id : $position->profitSharingData->exchangeData->userId;
        $positionExchangeInternalId = empty($position->profitSharingData) ? $position->exchange->internalId : $position->profitSharingData->exchangeData->internalId;

        $order = $ExchangeCalls->sendOrder(
            $positionUserId,
            $positionExchangeInternalId,
            $positionMediator->getSymbolWithSlash(),
            ExchangeOrderType::Stop,
            $positionMediator->isShort() ? 'buy' : 'sell',
            $amount,
            $stopPrice,
            $options,
            true,
            $position->_id->__toString()
        );

        if (is_object($order)) {
            updateSuccessfulOrder($newPositionCCXT, $order, $amount, $position);
            //$headMessage = "*NEW:* Stop-market order placed.\n";
            $headMessage = false;
        } else {
            if (!is_array($order)) {
                $order = [$order];
            }
            $Monolog->sendEntry('critical', "Placing stop-loss order failed", $order);
            $headMessage = "*ERROR:* Stop-market order couldn't be placed.\n";
        }
        if ($headMessage) {
            sendNotification($position, $headMessage);
        }
    }
}

/**
 * Because the total amount could be above the maximum limit, we return an array of amounts to do an order per item.
 * @param Accounting $Accounting
 * @param BSONDocument $position
 * @param ExchangeCalls $ExchangeCalls
 * @param PositionMediator $positionMediator
 * @return array
 */
function getAmounts(
    Accounting $Accounting,
    BSONDocument $position,
    ExchangeCalls $ExchangeCalls,
    PositionMediator $positionMediator
) {
    list(, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($position);
    $amountToReduce = $ExchangeCalls->getAmountToPrecision($remainAmount, $positionMediator->getSymbol());

    return $positionMediator->getExchangeMediator()->getExchangeHandler()->getMaxAmountsForMarketOrders(
        $amountToReduce,
        $positionMediator->getSymbol()
    );
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
 * @param float $amount
 * @param BSONDocument $position
 * @return bool|\MongoDB\BSON\ObjectId
 */
function updateSuccessfulOrder(newPositionCCXT $newPositionCCXT, ExchangeOrder $order, float $amount, BSONDocument $position)
{
    $orderData = [
        'orderId' => $order->getId(),
        'status' => $order->getStatus(),
        'type' => 'stop',
        'price' => $order->getStopPrice(),
        'amount' => $order->getAmount(),
        'originalAmount' => $amount,
        'cost' => $order->getCost(),
        'transacTime' => new \MongoDB\BSON\UTCDateTime($order->getTimestamp()),
        'orderType' => $order->getType(),
        'done' => false,
        'positionStatus' => 16,
        'reduceOnly' => $order->getReduceOnly(),
        'clientOrderId' => $order->getRecvClientId(),
    ];

    $setPosition = [
        'orders.' . $order->getId() => $orderData,
    ];

    $pushOrder = [
        'order' => [
            '$each' => [$orderData],
        ],
    ];

    return $newPositionCCXT->setPosition($position->_id, $setPosition, true, $pushOrder);
}

/**
 * Requeue position.
 *
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param string $positionId
 * @param string $queueName
 * @return int
 */
function sendStopOrderBackToQueue(RedisHandler $RedisHandlerZignalyQueue, string $positionId, string $queueName)
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
) {
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
