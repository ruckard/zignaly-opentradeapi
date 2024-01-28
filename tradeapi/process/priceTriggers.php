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

$excludeRabbit = true;
$excludeMongo = true;
require_once __DIR__ . '/../loader.php';
global $continueLoop;

$processName = 'priceTriggers';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName, ':bangbang', false));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');
/** @var RedisHandler $RedisTriggersWatcher */
$RedisTriggersWatcher = $container->get('redis.triggersWatcher');
$scriptStartTime = time();
$queueName = 'priceTriggersQueue';

do {
    try {
        $popMember = $RedisTriggersWatcher->popFromSetOrBlock($queueName);
        if (empty($popMember)) {
            continue;
        }

        list($exchangeName, $exchangeType, $symbol, $price, $timestamp) = explode(':', $popMember[1]);

        $gteKey = "$exchangeName:$exchangeType:$symbol:gte";
        $lteKey = "$exchangeName:$exchangeType:$symbol:lte";

        $gteMembers = $RedisTriggersWatcher->zRangeByScore($gteKey, '-inf', $price);
        $gteParsedMembers = sendMembersToQueues($Monolog, $gteMembers);
        $lteMembers = $RedisTriggersWatcher->zRangeByScore($lteKey, $price, '+inf');
        $lteParsedMembers = sendMembersToQueues($Monolog, $lteMembers);
        $pipeline = array_merge($gteParsedMembers, $lteParsedMembers);
        if (!empty($pipeline)) {
            $RedisHandlerZignalyQueue->addSortedSetPipelineWithDynamicData($pipeline);
        }
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Unknown error: " . $e->getMessage());
        continue;
    }
} while ($continueLoop);

/**
 * Prepare the members for sending to the queue by pipeline.
 * @param Monolog $Monolog
 * @param array $members
 * @return array
 */
function sendMembersToQueues(Monolog $Monolog, array $members)
{
    if (empty($members)) {
        return [];
    }
    $data = [];
    foreach ($members as $member) {
        list($triggerName, $orderId, $positionId, $queueName, $status) = explode(':', $member);
        $queueName = 'quickPriceWatcher_PriceTriggers';

        $datum = [
            'score' => time(),
            'key' => $queueName,
            'value' => $positionId,
            'option' => 'NX',
        ];
        $data[] = $datum;
        //$Monolog->sendEntry('debug', "Trigger $triggerName", $datum);
    }

    return $data;
}
