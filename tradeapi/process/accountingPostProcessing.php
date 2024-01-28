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


use Zignaly\Messaging\Messages\AccountingDone;
use Zignaly\Metrics\MetricServiceInterface;
use Zignaly\Process\DIContainer;

$createSecondaryDBLink = true;
require_once __DIR__ . '/../loader.php';
global $Accounting, $Exchange, $RabbitMQ, $Monolog, $newPositionCCXT, $continueLoop;

$processName = 'accountingPostProcessing';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
$newPositionCCXT->configureLoggingByContainer($container);
$newPositionCCXT->configureMongoDBLinkRO();
$RedisHandlerZignalyQueue = $container->get('redis.queue');
$Signal = $container->get('signal.model');
$Dispatcher = $container->get('dispatcher');
/** @var Notification */
$notification = $container->get('Notification');
/** @var MetricServiceInterface */
$MetricService = $container->get('metricService');

$RedisLockController = $container->get('RedisLockController');

$scriptStartTime = time();
$isLocal = getenv('LANDO') === 'ON';
$queueName = 'accounting_done';
$positionId = !empty($argv['1']) ? $argv['1'] : false;
$ExchangeCalls = new ExchangeCalls($Monolog);

do {
    $Monolog->trackSequence();
    $Monolog->addExtendedKeys('queueName', $queueName);
    $startTime = time();
    $workingAt = time();

    list($position, $inQueueAt) = getPosition($Monolog, $newPositionCCXT, $RedisHandlerZignalyQueue, $RedisLockController, $processName, $queueName, $positionId);

    if (empty($positionId) && !empty($position->accountingPostProcessing)) {
        $Monolog->sendEntry('debug', "Position already post-processed.");
        continue;
    }

    if (!isset($position->accounting) || empty($position->accounting->done)) {
        //$Monolog->sendEntry('debug', "Nothing to do.");
        if ($isLocal) {
            sleep(1);
        }
        continue;
    }

    if (!$position->closed) {
        continue;
    }

    $Monolog->sendEntry('debug', "New position");

    if (!$positionId) {
        sendNotification($RabbitMQ, 'positionSoldSuccess', $position);
        $event = [
            'type' => 'closePosition',
            'userId' => $position->user->_id->__toString(),
            'parameters' => [
                'positionId' => $position->_id->__toString(),
            ],
            'timestamp' => time(),
        ];
        $RedisHandlerZignalyQueue->insertElementInList('eventsQueue', json_encode($event));
    }

    if (!empty($position->provider->isCopyTrading)) {
        recalculateCopyTradingProfitsForUserFromClosedPositions($Monolog, $newPositionCCXT, $position);
    }

    if (!empty($position->profitSharingData)) {
        $message = json_encode(['providerId' => $position->provider->_id], JSON_PRESERVE_ZERO_FRACTION);
        $RedisHandlerZignalyQueue->addSortedSet('profitSharingAccountingQueue', time(), $message, true);
    }

    //This is for generating the cache of closed positions.
    if ($position->_id) {
        $message = new AccountingDone;
        $message->positionId = (string)$position->_id;
        $Dispatcher->sendAccountingDone($message);
    }

    $newPositionCCXT->setPosition($position->_id, ['accountingPostProcessing' => true]);
    $RedisLockController->removeLockPositionEntryFromRedis($position->_id->__toString());

    if (!$positionId && time() - $startTime < 10) {
        sleep(10);
    }
} while ($continueLoop && !$positionId);

/**
 * If the position is from a copy-trading service, it recalculated the profits from closed position balance for
 * the user and updated it.
 *
 * @param Monolog $Monolog
 * @param newPositionCCXT $newPositionCCXT
 * @param \MongoDB\Model\BSONDocument $user
 * @param \MongoDB\Model\BSONDocument $position
 */
function recalculateCopyTradingProfitsForUserFromClosedPositions(
    Monolog &$Monolog,
    newPositionCCXT $newPositionCCXT,
    \MongoDB\Model\BSONDocument $position
) {
    global $newUser;

    $newUser->configureLogging($Monolog);

    $providerId = isset($position->signal->providerId) ? $position->signal->providerId : false;

    if (!$providerId) {
        $Monolog->sendEntry('error', "Missing signal providerId in copy trading");
    } else {
        $user = $newUser->getUser($position->user->_id);
        $providerIdString = is_object($providerId) ? $providerId->__toString() : $providerId;
        if ($position->exchange->internalId == $user->provider->$providerIdString->exchangeInternalId) {
            $fromDate = isset($user->provider) && isset($user->provider->$providerIdString) &&
                isset($user->provider->$providerIdString->allocatedBalanceUpdatedAt)
                ? $user->provider->$providerIdString->allocatedBalanceUpdatedAt : $user->createdAt;
            $sinceDate = isset($user->provider) && isset($user->provider->$providerIdString) &&
                isset($user->provider->$providerIdString->resetProfitSinceCopyingAt)
                ? $user->provider->$providerIdString->resetProfitSinceCopyingAt : $user->createdAt;
            $setUser = [
                'provider.' . $providerIdString . '.profitsFromClosedBalance' => $newPositionCCXT->calculateProfitFromCopyTradingClosedPositions($position->user->_id, $position->provider->_id, $fromDate),
                'provider.' . $providerIdString . '.profitsSinceCopying' => $newPositionCCXT->calculateProfitFromCopyTradingClosedPositions($position->user->_id, $position->provider->_id, $sinceDate),
            ];

            $newUser->updateUser($position->user->_id, $setUser);
        }
    }
}

/**
 * Prepare a message for sending a notification to the user.
 *
 * @param string $command
 * @param \MongoDB\Model\BSONDocument $position
 * @param bool $error
 */
function sendNotification(RabbitMQ $RabbitMQ, string $command, \MongoDB\Model\BSONDocument $position, $error = false)
{
    $parameters = [
        'userId' => $position->user->_id->__toString(),
        'positionId' => $position->_id->__toString(),
        'status' => $position->status,
    ];

    if ($error) {
        $parameters['error'] = $error;
    }

    $message = [
        'command' => $command,
        'chatId' => false,
        'code' => false,
        'parameters' => $parameters
    ];

    $message = json_encode($message, JSON_PRESERVE_ZERO_FRACTION);
    $RabbitMQ->publishMsg('profileNotifications', $message);
}

/**
 * Get the position from a given id or from the list.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param RedisLockController $RedisLockController
 * @param string $processName
 * @param string $queueName
 * @param bool|string $positionId
 * @return array
 */
function getPosition(
    Monolog $Monolog,
    newPositionCCXT $newPositionCCXT,
    RedisHandler $RedisHandlerZignalyQueue,
    RedisLockController $RedisLockController,
    string $processName,
    string $queueName,
    $positionId
) {
    if (!$positionId) {
        $popMember = $RedisHandlerZignalyQueue->popFromSetOrBlock($queueName);
        if (!empty($popMember)) {
            $positionId = $popMember[1];
            $inQueueAt = $popMember[2];
        } else {
            return [false, false];
        }
        $previousPositionId = false;
    } else {
        $previousPositionId = true;
        $inQueueAt = time();
    }

    $position = $newPositionCCXT->getPosition($positionId);

    if (!empty($position->user)) {
        $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());
    }

    if (!$previousPositionId) {
        //if (empty($position->_id) || !$RedisLockController->positionHardLock($position->_id->__toString(), $processName, 600, true)) {
        if (empty($position->_id) || !$RedisLockController->positionSoftLock($position->_id->__toString(), $processName)) {

            return [false, false];
        }
    }

    return [$position, $inQueueAt];
}
