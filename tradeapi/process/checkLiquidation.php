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


use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use \MongoDB\Model\BSONDocument;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\BitmexPositionMediator;

require_once __DIR__ . '/../loader.php';
global $RabbitMQ, $continueLoop;

$processName = 'checkLiquidation';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
$ExchangeCalls = $container->get('exchangeMediator');
$newPosition = $container->get('newPositionCCXT.model');
$newPosition->configureLoggingByContainer($container);
$newPosition->initiateAccounting();
$RedisLockController = $container->get('RedisLockController');
$RedisHandlerZignalyQueue = $container->get('redis.queue');
/** @var Provider $Provider */
$Provider = $container->get('provider.model');

$positionId = (isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : false;
$scriptStartTime = time();
$isLocal = getenv('LANDO') === 'ON';

do {
    $Monolog->trackSequence();
    $workingAt = time();
    try {
        list($position, $inQueueAt) = getPosition(
            $newPosition,
            $RedisHandlerZignalyQueue,
            $RedisLockController,
            $processName,
            'checkLiquidationQueue',
            $positionId
        );
        if (!$position) {
            sleep($isLocal ? 2 : 0);
            continue;
        }

        $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());

        $positionMediator = PositionMediator::fromMongoPosition($position);

        // remove bitmex for now
        // if ($positionMediator instanceof BitmexPositionMediator) {
        //    $Monolog->sendEntry('debug', 'bypass BitMEX for now');
        //    $RedisLockController->removeLock($position->_id->__toString(), $processName, 'soft');
        //    continue;
        //}
        

        if (!$ExchangeCalls->setCurrentExchange(
            $positionMediator->getExchange()->getId(),
            $positionMediator->getExchangeType(),
            $positionMediator->getExchangeIsTestnet()
        )) {
            $Monolog->sendEntry('critical', 'Error connecting the exchange');
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'soft');
            continue;
        }

        if (!$ExchangeCalls->reConnectExchangeWithKeys($position->user->_id, $position->exchange->internalId)) {
            $Monolog->sendEntry('debug', 'Error authenticating the user keys in the exchange');
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'soft');
            continue;
        }

        if (!checkAndUpdateLiquidationOrders($Monolog, $ExchangeCalls, $newPosition, $RedisLockController, $position, $processName)) {
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'soft');
        } else {
            if (!empty($position->profitSharingData)) {
                $Provider->updateServiceLiquidatedFlat($position->signal->providerId);
                $Monolog->sendEntry('alert', "ProfitSharing Service liquidated: {$position->provider->_id}");
            }
        }
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', $e->getMessage());
    }

    if ($isLocal) {
        sleep(2);
    }

    /*$elapsedTime = time() - $workingAt;
    $inQueue = $workingAt - $inQueueAt;
    if ($inQueue > 150) {
        $Monolog->sendEntry('error', "Queue performance",
            [
                'startedWorkingAt' => $workingAt,
                'timeProcessing' => $elapsedTime,
                'inQueue' => $inQueue,
            ]
        );
    }*/
} while ($continueLoop && !$positionId);

function checkAndUpdateLiquidationOrders(
    Monolog $Monolog,
    ExchangeCalls $ExchangeCalls,
    newPositionCCXT $newPosition,
    RedisLockController $RedisLockController,
    BSONDocument $position,
    string $processName
)
{
    $forceOrders = $ExchangeCalls->getForceOrders($position);

    if (isset($forceOrders['error'])) {
        return false;
    }

    if (empty($forceOrders)) {
        return false;
    }

    $position = $RedisLockController->positionHardLock($position->_id->__toString(), $processName);

    if (!$position) {
        //$Monolog->sendEntry('debug', 'Error authenticating the user keys in the exchange');
        return false;
    }

    if ($position->closed) {
        $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
        return false;
    }

    if ($newPosition->checkIfPositionHasBeenLiquidated($ExchangeCalls, $position, $forceOrders)) {
        $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
        return true;
    }

    return false;
}

/**
 * Get the position from a given id or from the list.
 *
 * @param newPositionCCXT $newPosition
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param RedisLockController $RedisLockController
 * @param string $processName
 * @param bool|string $positionId
 * @param string $queueName
 * @return array
 */
function getPosition(
    newPositionCCXT $newPosition,
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

    $position = $newPosition->getPosition($positionId);

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