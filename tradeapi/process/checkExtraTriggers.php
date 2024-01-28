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


use Zignaly\Process\DIContainer;
use \MongoDB\Model\BSONDocument;
use Zignaly\Mediator\PositionMediator;
use Zignaly\service\ZignalyLastPriceService;

require_once __DIR__ . '/../loader.php';
global $RabbitMQ, $continueLoop;

$processName = 'checkExtraTriggers';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
$ExchangeCalls = $container->get('exchangeMediator');
$newPositionCCXT = $container->get('newPositionCCXT.model');
$newPositionCCXT->configureLoggingByContainer($container);
/** @var newUser $newUser */
$newUser = $container->get('newUser.model');
$lastPriceService = $container->get('lastPrice');
/** @var RedisLockController  $RedisLockController */
$RedisLockController = $container->get('RedisLockController');
$RedisHandlerZignalyQueue = $container->get('redis.queue');
$queueName = (isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : 'checkExtraTriggersQueue';
$positionId = (isset($argv['2'])) && $argv['2'] != 'false' ? $argv['2'] : false;
$scriptStartTime = time();
$isLocal = getenv('LANDO') === 'ON';

do {
    $Monolog->trackSequence();
    $Monolog->addExtendedKeys('queueName', $queueName);
    $workingAt = time();
    try {
        list($position, $inQueueAt) = getPosition($newPositionCCXT, $RedisHandlerZignalyQueue, $RedisLockController, $processName, $queueName, $positionId);
        if (!$position) {
            sleep($isLocal ? 2 : 0);
            continue;
        }
        $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());

        if (!checkIfExchangeKeysAreValid($newUser, $position)) {
            $Monolog->sendEntry('warning', "Keys aren't valid.");
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'soft');
            continue;
        }

        if (checkBuyTTL($Monolog, $position)) {
            $Monolog->sendEntry('warning', "buy TTL triggered");
            removeInitialOrder($RedisLockController, $Monolog, $newPositionCCXT, $position, $ExchangeCalls, $lastPriceService, 4, $processName);
        } elseif (checkSellTTL($Monolog, $position)) {
            $Monolog->sendEntry('warning', "sellByTTLL triggered");
            sendPositionToStopLossQueue($position, $RabbitMQ);
            $setPosition = [
                'updating' => true,
                'lastUpdatingAt' => new \MongoDB\BSON\UTCDateTime(),
            ];
            $newPositionCCXT->setPosition($position->_id, $setPosition);
        } elseif (checkIfInitialBuyOrderRetired($Monolog, $position)) {
            $Monolog->sendEntry('warning', "manual entry cancel triggered");
            removeInitialOrder($RedisLockController, $Monolog, $newPositionCCXT, $position, $ExchangeCalls, $lastPriceService, 41, $processName);
        }

        //$Monolog->sendEntry('debug', "Check finished.");
    } catch (Exception $e) {
        $Monolog->sendEntry('debug', $e->getMessage()); //Todo: Review this errors
    }

    if (isset($position->status)) {
        $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
    }

    if ($isLocal) {
        sleep(2);
    }
} while ($continueLoop && !$positionId);

/**
 * Check if the position exchange has valid keys.
 * @param newUser $newUser
 * @param BSONDocument $position
 * @return bool
 */
function checkIfExchangeKeysAreValid(newUser $newUser, BSONDocument $position)
{
    $user = $newUser->getUser($position->user->_id);
    if (empty($user->exchanges)) {
        return false;
    }

    foreach ($user->exchanges as $exchange) {
        if ($exchange->internalId !== $position->exchange->internalId) {
            continue;
        }

        return $exchange->areKeysValid;
    }

    return false;
}

/**
 * Send position to stop loss queue
 * @param BSONDocument $position
 * @param RabbitMQ $RabbitMQ
 */
function sendPositionToStopLossQueue(BSONDocument $position, RabbitMQ $RabbitMQ)
{
    $message = json_encode([
        'positionId' => $position->_id->__toString(),
        'status' => 30,
    ], JSON_PRESERVE_ZERO_FRACTION);
    $queueName = empty($position->paperTrading) && empty($position->testNet) ? 'stopLoss' : 'stopLoss_Demo';
    $RabbitMQ->publishMsg($queueName, $message);
}


/**
 * Remove the initial entry order.
 *
 * @param RedisLockController $RedisLockController
 * @param Monolog $Monolog
 * @param newPositionCCXT $newPositionCCXT
 * @param BSONDocument $position
 * @param ExchangeCalls $ExchangeCalls
 * @param ZignalyLastPriceService $lastPriceService
 * @param string $status
 * @param string $processName
 * @return bool
 * @throws Exception
 */
function removeInitialOrder(
    RedisLockController $RedisLockController,
    Monolog $Monolog,
    newPositionCCXT &$newPositionCCXT,
    BSONDocument $position,
    ExchangeCalls $ExchangeCalls,
    ZignalyLastPriceService $lastPriceService,
    string $status,
    string $processName
) {
    $requeue = false;
    $position = $RedisLockController->positionHardLock($position->_id->__toString(), $processName, 50);
    if (!$position) {
        return true;
    }
    $numberOfInitialEntryOrders = 'MULTI' === $position->buyType
        ? ['first', 'second'] : ['unique'];
    //$Monolog->sendEntry('debug', 'Position with these initial entries: ', $numberOfInitialEntryOrders);
    foreach ($numberOfInitialEntryOrders as $firstOrSecond) {
        if ('second' === $firstOrSecond) {
            $position = $newPositionCCXT->getPosition($position->_id);
        }
        $orderId = getInitialEntryOrder($position, $firstOrSecond);
        if (!$orderId) {
            return false;
        }

        $positionMediator = PositionMediator::fromMongoPosition($position);
        //$Monolog->sendEntry('debug', "Initial entry $firstOrSecond: $orderId");
        $exchangeName = $positionMediator->getExchange()->getId();
        $exchangeAccountType = $positionMediator->getExchangeType();
        $isTestnet = $positionMediator->getExchangeIsTestnet();
        if (!$ExchangeCalls->setCurrentExchange($exchangeName, $exchangeAccountType, $isTestnet)) {
            //$Monolog->sendEntry('critical', 'Error connecting the exchange');
            $requeue = true;
        } else {
            $newPositionCCXT->configureExchangeCalls($ExchangeCalls);
            $newPositionCCXT->configureLastPriceService($lastPriceService);
            $CheckOrdersCCXT = new CheckOrdersCCXT($position, $ExchangeCalls, $newPositionCCXT, $Monolog);
            $remainingEntryOrders = 'first' === $firstOrSecond;
            $requeue = $CheckOrdersCCXT->cancelInitialOrder($processName, $status, $orderId, $remainingEntryOrders);
        }
    }
    return $requeue;
}

/**
 * Get the first order or false if it doesn't exist or it's done.
 *
 * @param BSONDocument $position
 * @param string $firstOrSecond
 * @return bool|string
 */
function getInitialEntryOrder(BSONDocument $position, string $firstOrSecond)
{
    if (empty($position->orders)) {
        return false;
    }

    if ('MULTI' !== $position->buyType) {
        foreach ($position->orders as $order) {
            if ($order->done) {
                return false;
            } else {
                return $order->orderId;
            }
        }
    } else {
        foreach ($position->orders as $order) {
            if ($order->done || ('buy' !== $order->side && 'first' === $firstOrSecond)) {
                continue;
            } else {
                return $order->orderId;
            }
        }
    }

    return false;
}

/**
 * Check if the position has been requested to cancel manually.
 *
 * @param Monolog $Monolog
 * @param BSONDocument $position
 * @return bool
 */
function checkIfInitialBuyOrderRetired(Monolog $Monolog, BSONDocument $position)
{
    //$Monolog->sendEntry('debug', "Starting manual cancel check");

    return 1 === $position->status && !empty($position->manualCancel);
}

/**
 * Check if the position should be closed because any sell have been performed before the TTL.
 *
 * @param Monolog $Monolog
 * @param BSONDocument $position
 * @param bool $returnHasBeenDisable
 * @return bool
 */
function checkSellTTL(Monolog $Monolog, BSONDocument $position, bool $returnHasBeenDisable = false)
{
    if (empty($position->sellByTTL) && empty($position->exitByTTLAt)) {
        return false;
    }

    if (empty($position->forceExitByTTL) && checkIfAnyTakeProfitIsDone($position)) {
        return false;
    }

    if (empty($position->forceExitByTTL) && !empty($position->trailingStopPrice)) {
        return false;
    }

    if ($returnHasBeenDisable) {
        return true;
    }

    $buyingTime = isset($position->buyPerformedAt) && is_object($position->buyPerformedAt)
        ? $position->buyPerformedAt->__toString() / 1000 : $position->signal->datetime->__toString() / 1000;
    $maxSellTimeFromSellByTTL = $buyingTime + $position->sellByTTL;
    $maxSellTime = $maxSellTimeFromSellByTTL;
    if (!empty($position->exitByTTLAt)) {
        $maxSellTimeFromSellByTTLAt = $position->exitByTTLAt->__toString() / 1000;
        if ($maxSellTimeFromSellByTTLAt < $maxSellTime) {
            $maxSellTime = $maxSellTimeFromSellByTTLAt;
        }
    }

    return time() > $maxSellTime;
}

/**
 * Check if any take profit is done.
 *
 * @param BSONDocument $position
 * @return bool
 */
function checkIfAnyTakeProfitIsDone(BSONDocument $position)
{
    if (empty($position->takeProfitTargets)) {
        return false;
    }

    foreach ($position->takeProfitTargets as $target) {
        if (!empty($target->done) && !empty($target->orderId) && !empty($target->filledAt)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if the entry order needs to be removed because of TTL.
 *
 * @param Monolog $Monolog
 * @param BSONDocument $position
 * @param bool $returnHasBeenDisable
 * @return bool
 */
function checkBuyTTL(Monolog $Monolog, BSONDocument $position, bool $returnHasBeenDisable = false)
{
    /*if (!$returnHasBeenDisable)
        $Monolog->sendEntry('debug', "Starting buy by ttl trigger check");*/

    if (!empty($position->buyPerformed)) {
        return false;
    }

    if ($returnHasBeenDisable) {
        return true;
    }

    if (time() > $position->cancelBuyAt->__toString() / 1000) {
        return true;
    }

    return false;
}

/**
 * Get the position from a given id or from the list.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param RedisLockController $RedisLockController
 * @param string $processName
 * @param bool|string $positionId
 * @param string $queueName
 * @return array
 */
function getPosition(
    newPositionCCXT $newPositionCCXT,
    RedisHandler $RedisHandlerZignalyQueue,
    RedisLockController $RedisLockController,
    string $processName,
    string $queueName,
    $positionId
)
{
    if (!$positionId) {
        $popMember = $RedisHandlerZignalyQueue->popFromSetOrBlock($queueName);
        if (!empty($popMember)) {
            $positionId = $popMember[1];
            $inQueueAt = $popMember[2];
        } else {
            return [false, false];
        }
    } else {
        $inQueueAt = time();
    }

    $position = $newPositionCCXT->getPosition($positionId);

    if (!isset($position->closed) || $position->closed) {
        return [false, false];
    }

    if (!$positionId) {
        if (empty($position->_id) || !$RedisLockController->positionSoftLock($position->_id->__toString(), $processName)) {
            return [false, false];
        }
    }

    return [$position, $inQueueAt];
}