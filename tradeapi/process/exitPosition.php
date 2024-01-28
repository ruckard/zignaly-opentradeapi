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
global $continueLoop;

$processName = 'exitPosition';
$queueName = 'exitPosition';
$positionId = !empty($argv['1']) ? $argv['1'] : false;
$status = !empty($argv['2']) ? $argv['2'] : false;
$manual = !empty($positionId);
$continueLoop = !$manual;

$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
$ExitPosition = new ExitPosition($Monolog, $processName, $queueName);
/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');

do {
    try {
        $Monolog->trackSequence();
        list($positionId, $status) = getPositionForExit($RedisHandlerZignalyQueue, $queueName, $positionId, $status);
        if (!$positionId) {
            continue;
        }
        $response = $ExitPosition->process($positionId, $status, !$manual);
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Failed: Message: " . $e->getMessage());
    }
    $positionId = false;
} while ($continueLoop);

/**
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param string $queueName
 * @param $positionId
 * @param $status
 * @return array
 */
function getPositionForExit(RedisHandler $RedisHandlerZignalyQueue, string $queueName, $positionId, $status): array
{
    if (!$positionId) {
        $popMember = $RedisHandlerZignalyQueue->popFromSetOrBlock($queueName);
        if (!empty($popMember)) {
            $message = json_decode($popMember[1], true);
            $positionId = $message['positionId'];
            $status = empty($message['status']) ? 109 : $message['status'];
        } else {
            return [false, false];
        }
    }
    $status = empty($status) ? 109 : $status;

    return [$positionId, $status];
}