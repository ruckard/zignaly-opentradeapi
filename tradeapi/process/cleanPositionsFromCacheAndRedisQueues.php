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

require_once __DIR__ . '/../loader.php';
global $continueLoop;
$processName = 'cleanPositionsFromCacheAndRedisQueues';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
$newPositionCCXT = $container->get('newPositionCCXT.model');
$newPositionCCXT->configureLoggingByContainer($container);
$RedisHandlerZignalyQueue = $container->get('redis.queue');
//$PositionCacheGenerator = $container->get('PositionCacheGenerator');
$positionId = (isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : false;
$scriptStartTime = time();

do {
    $Monolog->trackSequence();
    try {
        $position = getPosition($newPositionCCXT, $RedisHandlerZignalyQueue, $Monolog, $positionId);
        $requeue = null;
        if (!$position) {
            continue;
        }
        $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());
        //$Monolog->sendEntry('debug', "Status: " . $position->status);

        //Removing position from open positions cache:
        //$PositionCacheGenerator->removePositionFromCache($position);

        //Removing position from quickPriceWatcher queue
        //Todo: We need a different way to clean this up.
        $RedisHandlerZignalyQueue->removeMemberFromSortedSet('quickPriceWatcher', $position->_id->__toString());

        //Removing position from checkExtraTriggers queue
        //$RedisHandlerZignalyQueue->removeMemberFromList('checkExtraTriggers', $position->_id->__toString());

        //$Monolog->sendEntry('debug', "Clean finished.");
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', $e->getMessage());
    }
} while ($continueLoop && !$positionId);


/**
 * Get the position from a given id or from the list.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param Monolog $Monolog
 * @param bool|string $positionId
 * @return bool|BSONDocument|object|null
 */
function getPosition(
    newPositionCCXT $newPositionCCXT,
    RedisHandler $RedisHandlerZignalyQueue,
    Monolog $Monolog,
    $positionId
)
{
    if ($positionId) {
        $position = $newPositionCCXT->getPosition($positionId);
    } else {
        $popMember = $RedisHandlerZignalyQueue->popFromSetOrBlock('removeFromCacheAndQueues');
        if (empty($popMember)) {
            return false;
        } else {
            $positionId = $popMember[1];
            $position = $newPositionCCXT->getPosition($positionId);
        }
    }

    return !empty($position->closed) ? $position : false;
}