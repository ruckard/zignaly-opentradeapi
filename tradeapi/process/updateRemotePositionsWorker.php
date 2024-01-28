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

use Zignaly\Messaging\Messages\UpdateRemoteClosedPositions;
use Zignaly\Process\DIContainer;
use Zignaly\Positions\ClosedPositionsService;

require_once __DIR__ . '/../loader.php';
global $continueLoop;
$processName = 'updateRemotePositionsWorker';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));

/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');

/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');

/** @var ClosedPositionsService $closedPositionsService */
$closedPositionsService = $container->get('closedPositionsService');

$queueName = 'updateCachePositionQueue';

do {
    try {
        $Monolog->trackSequence();

        $popMember = $RedisHandlerZignalyQueue->popFromSetOrBlock($queueName);

        if (empty($popMember)) {
            continue;
        }

        $message = UpdateRemoteClosedPositions::fromJson($popMember[1]);

        try {
            $closedPositionsService->updateRemoteClosedPositions(
                $message->userId,
                $message->internalExchangeId
            );
            $Monolog->sendEntry(
                'info', 
                sprintf(
                    'Processed - User: %s - InternalExchange: %s', 
                    $message->userId,
                    $message->internalExchangeId,
                )
            );
        }
        catch (\Throwable $e) {
            $Monolog->sendEntry(
                'error', 
                sprintf(
                    'Error - User: %s - InternalExchange: %s', 
                    $message->userId,
                    $message->internalExchangeId
                )
            );
            throw $e;
        }
    } catch (\Throwable $e) {
        $Monolog->sendEntry('critical', 'Unknown error: ' . $e->getMessage());
        if (true === stripos($e->getMessage(), 'OAUTH Authentication required') || true === stripos($e->getMessage(), 'Redis server')) {
            sleep(rand(1, 5));
            exit();
        }
    }
} while ($continueLoop);
