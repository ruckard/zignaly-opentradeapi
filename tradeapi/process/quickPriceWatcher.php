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

require_once __DIR__ . '/../loader.php';
global $newPositionCCXT, $RabbitMQ, $continueLoop;
$processName = 'quickPriceWatcher';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');
$ExchangeCalls = new ExchangeCalls($Monolog);
$lastPriceService = $container->get('lastPrice');
$newPositionCCXT->configureLoggingByContainer($container);
$newPositionCCXT->configureExchangeCalls($ExchangeCalls);
$newPositionCCXT->configureLastPriceService($lastPriceService);
$PriceWatcher = new PriceWatcher($Monolog, $newPositionCCXT, $RabbitMQ, $ExchangeCalls);
/** @var RedisLockController $RedisLockController */
$RedisLockController = $container->get('RedisLockController');
$queueName = (isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : false;
$positionId = (isset($argv['2'])) && $argv['2'] != 'false' ? $argv['2'] : false;
$exit = !empty($positionId);
$scriptStartTime = time();
while ($continueLoop) {
    $workingAt = time();
    $inQueue = false;
    try {
        $Monolog->trackSequence();
        $Monolog->addExtendedKeys('queueName', $queueName);
        //$RestartWorker->checkProcessStatus($processName, $scriptStartTime, $Monolog, 120);
        if ($exit) {
            $popMember = [
                false,
                $positionId,
                time(),
            ];
        } elseif (!$queueName) {
            $position = $newPositionCCXT->getPositionForQuickPriceWatcher();
            $popMember = [
                false,
                $position->_id->__toString(),
                time(),
            ];
        } else {
            $popMember = $RedisHandlerZignalyQueue->popFromSetOrBlock($queueName);
        }

        if (empty($popMember)) {
            //$Monolog->sendEntry('debug', "Nothing to do.");
            continue;
        }
        $positionId = $popMember[1];
        $Monolog->addExtendedKeys('positionId', $positionId);
        //$Monolog->sendEntry('debug', "New entry for position $positionId");
        if (!$exit && !$RedisLockController->positionSoftLock($positionId, $processName)) {
            //$Monolog->sendEntry('debug', "Couldn't get the Redis soft lock");
            if ($queueName) {
                $RedisHandlerZignalyQueue->addSortedSet($queueName, time(), $popMember[1]);
            }
            continue;
        }

        $timestamp = time() * 1000;
        if (!$PriceWatcher->checkPosition($positionId, $processName, $timestamp) && !$exit) {
            if ($queueName) {
                $RedisHandlerZignalyQueue->addSortedSet($queueName, time(), $popMember[1]);
            }
        }
        $RedisLockController->removeLock($positionId, $processName, 'all');
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Unknown error: " . $e->getMessage());
        if (true === stripos($e->getMessage(), 'OAUTH Authentication required') ||
            true === stripos($e->getMessage(), 'Redis server')) {
            sleep(rand(1, 5));
            exit();
        }
    }
    if ($exit) {
        exit();
    }
};
