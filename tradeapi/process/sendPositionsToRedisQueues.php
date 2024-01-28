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
use \MongoDB\Driver\Cursor;
use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangeOrder;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;

$secondaryDBNode = true;
$retryServerSelection = true;
$socketTimeOut = true;
$excludeRabbit = true;
require_once __DIR__ . '/../loader.php';
global $continueLoop, $RestartWorker;

$processName = 'sendPositionsToRedisQueues';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
/** @var newPositionCCXT $newPositionCCXT */
$newPositionCCXT = $container->get('newPositionCCXT.model');
$newPositionCCXT->configureLoggingByContainer($container);
/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');
$isLocal = getenv('LANDO') === 'ON';
$scriptStartTime = time();

while ($continueLoop) {
    try {
        $Monolog->trackSequence();
        $RestartWorker->checkProcessStatus($processName, $scriptStartTime, $Monolog, 120);

        //Get open positions for extraTriggersQueue process queue
        $positionsExtraTriggers = getPositionsForExtraTriggers($newPositionCCXT);
        sendPositionsToRedisQueue($positionsExtraTriggers, $RedisHandlerZignalyQueue, 'checkExtraTriggersQueue');

        //Get open positions for ReBuys process queue
        $positionsReBuys = getPositionsForReBuys($newPositionCCXT);
        sendPositionsToRedisQueue($positionsReBuys, $RedisHandlerZignalyQueue, 'reBuysQueue');

        //Get closed positions for accounting process queue
        $positionsAccounting = getPositionsForAccounting($newPositionCCXT);
        sendPositionsToRedisQueue($positionsAccounting, $RedisHandlerZignalyQueue, 'accountingQueue', 120);

        //Get futures positions to check if have been liquidated.
        //$positionsFromFutures = getPositionsFromFutures($newPositionCCXT);
        //sendPositionsToRedisQueue($positionsFromFutures, $RedisHandlerZignalyQueue, 'checkLiquidationQueue');
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Unknown error: " . $e->getMessage());
        if (strpos($e->getMessage(), "Authentication required")) {
            $Monolog->sendEntry('critical', "Terminating process");
            sleep(5);
            exit();
        }
        continue;
    }
}

/**
 * Extract the positions Id from the mongoDB cursor and send them as a pipeline to Redis queue.
 *
 * @param Cursor $positions
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param string $queueName
 * @param int $sinceClosed
 * @return bool
 */
function sendPositionsToRedisQueue(
    Cursor       $positions,
    RedisHandler $RedisHandlerZignalyQueue,
    string       $queueName,
    int          $sinceClosed = 0
)
{
    list($positionsId, $positionsId_Demo) = getIdsFromPositions($positions, $sinceClosed);
    if (empty($positionsId)) {
        return false;
    }

    $RedisHandlerZignalyQueue->addSortedSetPipeline($queueName, $positionsId);


    return true;
}

/**
 * Given a mongoDB cursor, extract the positions ID and return an array with them.
 *
 * @param Cursor $positions
 * @param int $sinceClosed
 * @return array
 */
function getIdsFromPositions(Cursor $positions, int $sinceClosed = 0)
{
    $positionsId = [];
    $positionsId_Demo = [];
    $score = time();
    foreach ($positions as $position) {
        if ($sinceClosed > 0) {
            if (!empty($position->closedAt) && is_object($position->closedAt)) {
                $closedAt = $position->closedAt->__toString() / 1000;
            } elseif (!empty($position->lastUpdate) && is_object($position->lastUpdate)) {
                $closedAt = $position->lastUpdate / 1000;
            } else {
                continue;
            }

            if (time() - $sinceClosed > $closedAt) {
                $positionsId[$position->_id->__toString()] = $score;
            }
        } else {
            $positionsId[$position->_id->__toString()] = $score;
        }
    }

    return [$positionsId, $positionsId_Demo];
}

/**
 * Get current open positions where extra triggers need to be reviewed.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @return Cursor
 */
function getPositionsForExtraTriggers(newPositionCCXT $newPositionCCXT)
{
    return $newPositionCCXT->getPositionsForCheckingExtraParameters();
}

/**
 * Get current open positions where there are pending reBuys.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @return Cursor
 */
function getPositionsForReBuys(newPositionCCXT $newPositionCCXT)
{
    return $newPositionCCXT->getPositionsWithPendingReBuys();
}


/**
 * Get closed positions that need to be accounted.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @return Cursor
 */
function getPositionsForAccounting(newPositionCCXT $newPositionCCXT)
{
    return $newPositionCCXT->getPositionsForAccounting(true);
}

/**
 * Get the current open positions from futures exchange to check if have been liquidated.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @return Cursor
 */
function getPositionsFromFutures(newPositionCCXT $newPositionCCXT)
{
    return $newPositionCCXT->getPositionsFromFutures();
}