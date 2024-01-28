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


use MongoDB\Model\BSONDocument;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;

class RedisTriggersWatcher
{

    /*
     * Members composition:
     * triggerName:orderId:positionId:queueName:status
     * If there isn't any orderId or status for the given member, 0 will be used.
     */
    /**
     * @var Monolog
     */
    private $Monolog;

    /**
     * @var RedisHandler
     */
    private $RedisTriggersWatcher;

    /**
     * @var string
     */
    private $side;

    /**
     * @var string
     */
    private $gteKey;

    /**
     * @var string
     */
    private $lteKey;

    /**
     * @var string
     */
    private $quickPriceWatcherQueue;

    /**
     * @var float
     */
    private $entryPrice;

    /**
     * @var array
     */
    private $activeMembers = [];

    public function __construct()
    {
        $container = DIContainer::getContainer();
        if ($container->has('monolog')) {
            $this->Monolog = $container->get('monolog');
        } else {
            $container->set('monolog', new Monolog('RedisTriggersWatcher'));
            $this->Monolog = $container->get('monolog');
        }
        $this->RedisTriggersWatcher = $container->get('redis.triggersWatcher');
    }

    public function preparePosition(BSONDocument $position)
    {
        return;
        $this->Monolog->addExtendedKeys('positionId', $position->_id->__toString());

        $this->activeMembers = [];
        $this->side = empty($position->side) ? 'LONG' : $position->side;

        $exchange = !empty($position->exchange->name) ? $position->exchange->name : '';
        if ('Zignaly' === $exchange) {
            $exchange = 'Binance';
        }

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeType = $positionMediator->getExchangeType();
        $symbol = $this->getSymbol($position);
        $this->gteKey = "$exchange:$exchangeType:$symbol:gte";
        $this->lteKey = "$exchange:$exchangeType:$symbol:lte";

        if (empty($position->closed)) {
            //For now orders check and trailing both are managed by the same process so they use the same queue.
            if (!empty($position->profitSharingData)) {
                $this->quickPriceWatcherQueue = 'quickPriceWatcher_PS';
            } elseif (!empty($position->paperTrading) || !empty($position->testNet)) {
                $this->quickPriceWatcherQueue = 'quickPriceWatcher_Demo';
            } else {
                $this->quickPriceWatcherQueue = 'quickPriceWatcher';
            }
            //$this->Monolog->sendEntry('debug', "New entry for queue {$this->quickPriceWatcherQueue}");

            $this->entryPrice = $this->getAverageEntryPrice($position);
            //$this->Monolog->sendEntry('debug', "Average entry price: {$this->entryPrice}");
            $this->addOpenPositionsMember($position);
            $this->removeOpenPositionMembers($position);
        } else {
            $this->removeClosedPositionMembers($position);
        }
    }

    /**
     * Overwrite the Monolog handler.
     * @param Monolog $Monolog
     */
    public function configureMonolog(Monolog $Monolog)
    {
        $this->Monolog = $Monolog;
    }

    /**
     * Parsing symbol because there is still old positions with wrong format.
     * @param BSONDocument $position
     * @return string
     */
    private function getSymbol(BSONDocument $position)
    {
        return strtoupper(preg_replace("/[^A-Za-z0-9 ]/", '', $position->signal->pair));
    }

    /**
     * Get the average entry price from a given position.
     * @param BSONDocument $position
     * @return float
     */
    private function getAverageEntryPrice(BSONDocument $position)
    {
        if (empty($position->avgBuyingPrice)) {
            $avgBuyingPrice = false;
        } else {
            $avgBuyingPrice = is_object($position->avgBuyingPrice) ? $position->avgBuyingPrice->__toString() : $position->avgBuyingPrice;
        }
        if (empty($position->limitPrice)) {
            $limitPrice = false;
        } else {
            $limitPrice = is_object($position->limitPrice) ? $position->limitPrice->__toString() : $position->limitPrice;
        }

        if (!empty($avgBuyingPrice)) {
            $entryPrice = $avgBuyingPrice;
        } elseif (!empty($limitPrice)) {
            $entryPrice = $limitPrice;
        } else {
            $entryPrice = 0;
        }

        return (float)$entryPrice;
    }

    /**
     * Get active triggers in the position, prepare members and send them to the sorted set.
     * @param BSONDocument $position
     */
    private function addOpenPositionsMember(BSONDocument $position)
    {
        $keys[$this->gteKey] = [];
        $keys[$this->lteKey] = [];

        $this->getStopLossMemberNameForInserting($position, $keys);

        $this->getTrailingStopMembersNameForInserting($position, $keys);

        $this->getOrdersMembersNameForInserting($position, $keys);

        $this->addMembersToKeys($keys);
        $this->activeMembers = $keys;
        //$this->Monolog->sendEntry('debug', "Adding all this members to its keys", $keys);
    }

    /**
     * Remove from redis DB the entries that may exist from this open position so we don't keep unneeded keys.
     * @param BSONDocument $position
     */
    private function removeOpenPositionMembers(BSONDocument $position)
    {
        $keys[$this->gteKey] = [];
        $keys[$this->lteKey] = [];

        if (empty($position->stopLossPercentage) && empty($position->stopLossPrice)) {
            $this->getStopLossMemberNameForRemoving($position, $keys);
        }

        if (empty($position->trailingStopTriggerPercentage) && empty($position->trailingStopTriggerPrice)) {
            $this->getTrailingStopTriggerMembersNameForRemoving($position, $keys);
        }

        if (empty($position->trailingStopPrice)) {
            $this->getTrailingStopDistanceMembersNameForRemoving($position, $keys);
        }

        if (!empty($position->orders)) {
            $this->getOrdersMembersNameForRemoving($position, $keys, true);
        }

        $this->removeMembersFromKeys($keys);
        //$this->Monolog->sendEntry('debug', "Removing all these members from their keys", $keys);
    }

    /**
     * Remove from redis DB the entries that may exist from this closed position.
     * @param BSONDocument $position
     */
    private function removeClosedPositionMembers(BSONDocument $position)
    {
        $keys[$this->gteKey] = [];
        $keys[$this->lteKey] = [];

        $this->getStopLossMemberNameForRemoving($position, $keys);
        $this->getTrailingStopTriggerMembersNameForRemoving($position, $keys);
        $this->getTrailingStopDistanceMembersNameForRemoving($position, $keys);
        $this->getOrdersMembersNameForRemoving($position, $keys, false);

        $this->removeMembersFromKeys($keys);
        //$this->Monolog->sendEntry('debug', "Removing all these members from the closed position", $keys);
    }

    /**
     * Update the keys array with members that need to be inserted from orders
     * @param BSONDocument $position
     * @param array $keys
     */
    private function getOrdersMembersNameForInserting(BSONDocument $position, array & $keys)
    {
        //$this->Monolog->sendEntry('debug', "Checking if there is any order");
        if (empty($position->orders)) {
            return;
        }

        //If the order is entry and long, the price would be checked in a sorted set "lte", otherwise "gte".
        $entryKeyName = 'LONG' === $this->side ? $this->lteKey : $this->gteKey;
        //If the order is entry and short, the price would be checked in a sorted set "gte", otherwise "lte".
        $exitKeyName = 'SHORT' === $this->side ? $this->lteKey : $this->gteKey;
        //$this->Monolog->sendEntry('debug', "Preparing keys for orders");
        foreach ($position->orders as $order) {
            if ($order->done) {
                continue;
            }
            $score = $this->getOrderPriceForScore($order);
            if ('MULTI' === $position->buyType) {
                $keyName = 'buy' === $order->side ? $entryKeyName : $exitKeyName;
            } elseif ('entry' === $order->type) {
                $keyName = $entryKeyName;
            } else {
                $keyName = $exitKeyName;
            }
            $keys[$keyName]["order:{$order->orderId}:{$position->_id->__toString()}:$this->quickPriceWatcherQueue:0"] = $score;
        }
    }

    /**
     * Return the order price for using as score, so if the orderType is market, it has to be checked always,
     * so we send 0.0 or 99999999.9 based on side to be sure that it matches always.
     * @param $order
     * @return float
     */
    private function getOrderPriceForScore($order)
    {
        if ('market' === $order->orderType) {
            if ('LONG' === $this->side && 'entry' === $order->type) {
                $price = 99999999.9;
            } elseif ('SHORT' === $this->side && 'entry' !== $order->type) {
                $price = 99999999.9;
            } else {
                $price = 0.0;
            }
        } else {
            $price = is_object($order->price) ? $order->price->__toString() : $order->price;
        }

        return (float)$price;
    }

    /**
     * Update the keys array with members that need to be removed from orders
     * @param BSONDocument $position
     * @param array $keys
     * @param bool $onlyDone
     */
    private function getOrdersMembersNameForRemoving(BSONDocument $position, array & $keys, bool $onlyDone)
    {
        if (empty($position->orders)) {
            return;
        }

        //If the order is entry and long, the price would be checked in a sorted set "lte", otherwise "gte".
        $entryKeyName = 'LONG' === $this->side ? $this->lteKey : $this->gteKey;
        //If the order is entry and short, the price would be checked in a sorted set "gte", otherwise "lte".
        $exitKeyName = 'SHORT' === $this->side ? $this->lteKey : $this->gteKey;
        foreach ($position->orders as $order) {
            if (!$onlyDone || $order->done) {
                if ('MULTI' === $position->buyType) {
                    $keyName = 'buy' === $order->side ? $entryKeyName : $exitKeyName;
                } elseif ('entry' === $order->type) {
                    $keyName = $entryKeyName;
                } else {
                    $keyName = $exitKeyName;
                }
                $keys[$keyName][] = "order:{$order->orderId}:{$position->_id->__toString()}:$this->quickPriceWatcherQueue:0";
            }
        }
    }

    /**
     * Update the keys array with members that need to be inserted from trailingStopLoss
     * @param BSONDocument $position
     * @param array $keys
     */
    private function getTrailingStopMembersNameForInserting(BSONDocument $position, array & $keys)
    {
        //$this->Monolog->sendEntry('debug', "Checking if entry is completed.");
        if (empty($position->buyPerformed)) {
            return;
        }

        //$this->Monolog->sendEntry('debug', "Checking if there is trailing stop percentage or price");
        if (empty($position->trailingStopTriggerPercentage) && empty($position->trailingStopTriggerPrice)) {
            return;
        }

        $score = $this->getTrailingStopLossTriggerPrice($position);
        //$this->Monolog->sendEntry('debug', "Trailing stop trigger: $score");
        if ($score) {
            //If the position is long, then the trailing stop trigger should be triggered whenever the current price is above it, ergo
            //we look in the sorted set named "gte". Otherwise "lte".
            $keyName = 'SHORT' === $this->side ? $this->lteKey : $this->gteKey;
            //$keys[$keyName]["trailingStopTrigger:0:{$position->_id->__toString()}:$this->quickPriceWatcherQueue:0:$score"] = $score; //Todo: we are removing price from the keyname.
            $keys[$keyName]["trailingStopTrigger:0:{$position->_id->__toString()}:$this->quickPriceWatcherQueue:0"] = $score;
        }
        //$this->Monolog->sendEntry('debug', "Checking if trigger has already been triggered");
        if ($position->trailingStopPrice) {
            $score = is_object($position->trailingStopPrice) ? (float)$position->trailingStopPrice->__toString() : (float)$position->trailingStopPrice;
            //If the position is long, then the trailing stop distance should be triggered whenever the current price is below it, ergo
            //we look in the sorted set named "lte". Otherwise "gte".
            $keyName = 'LONG' === $this->side ? $this->lteKey : $this->gteKey;
            $queue = !empty($position->version) && 3 === $position->version ? 'exitPosition' : 'stopLoss';
            $keys[$keyName]["trailingStopDistance:0:{$position->_id->__toString()}:$queue:18"] = $score;
            //$this->Monolog->sendEntry('debug', "Preparing trailing stop distance trigger for queue $queue");

        }
    }

    /**
     * Get the trailing stop loss trigger price from a position
     * @param BSONDocument $position
     * @return bool|float
     */
    private function getTrailingStopLossTriggerPrice(BSONDocument $position)
    {

        if (!empty($position->trailingStopTriggerPriority) && 'price' === $position->trailingStopTriggerPriority && !empty($position->trailingStopTriggerPrice)) {
            $trailingStopTriggerPrice = round($position->trailingStopTriggerPrice, 12, PHP_ROUND_HALF_DOWN);
        } else {
            $trailingStopTriggerPrice = !is_numeric($position->trailingStopTriggerPercentage) ? false
                : round($this->entryPrice * $position->trailingStopTriggerPercentage, 12, PHP_ROUND_HALF_DOWN);
        }

        return $trailingStopTriggerPrice;
    }

    /**
     * Update the keys array with members that need to be removed from trailingStopLoss trigger.
     * @param BSONDocument $position
     * @param array $keys
     */
    private function getTrailingStopTriggerMembersNameForRemoving(BSONDocument $position, array & $keys)
    {
        //If the position is long, then the trailing stop trigger should be triggered whenever the current price is above it, ergo
        //we look in the sorted set named "gte". Otherwise "lte".
        $keyName = 'SHORT' === $this->side ? $this->lteKey : $this->gteKey;
        $keys[$keyName][] = "trailingStopTrigger:0:{$position->_id->__toString()}:$this->quickPriceWatcherQueue:0";
    }

    /**
     * Update the keys array with members that need to be removed from trailingStopLoss distance.
     * @param BSONDocument $position
     * @param array $keys
     */
    private function getTrailingStopDistanceMembersNameForRemoving(BSONDocument $position, array & $keys)
    {
        //If the position is long, then the trailing stop distance should be triggered whenever the current price is below it, ergo
        //we look in the sorted set named "lte". Otherwise "gte".
        $keyName = 'LONG' === $this->side ? $this->lteKey : $this->gteKey;
        $queue = !empty($position->version) && 3 === $position->version ? 'exitPosition' : 'stopLoss';
        $keys[$keyName][] = "trailingStopDistance:0:{$position->_id->__toString()}:$queue:18";
    }

    /**
     * Update the keys array with members that need to be inserted from stopLoss
     * @param BSONDocument $position
     * @param array $keys
     */
    private function getStopLossMemberNameForInserting(BSONDocument $position, array & $keys)
    {
        //$this->Monolog->sendEntry('debug', "Checking if entry is completed.");
        if (empty($position->buyPerformed)) {
            return;
        }

        //$this->Monolog->sendEntry('debug', "Checking if stop loss percentage of price exists.");
        if (empty($position->stopLossPercentage) && empty($position->stopLossPrice)) {
            return;
        }

        $score = $this->getStopLossPrice($position);
        //$this->Monolog->sendEntry('debug', "Stop loss price: $score");
        if (('LONG' === $position->side && $score <= $this->entryPrice) || 'SHORT' === $position->side && $score >= $this->entryPrice) {
            //$this->Monolog->sendEntry('debug', "Position with side {$position->side} depending on DCAs:");
            if (!$this->areAllDCATargetsDone($position)) {
                //$this->Monolog->sendEntry('debug', "Pending DCAs so not placing stop loss trigger.");
                return;
            }
        }

        //$this->Monolog->sendEntry('debug', "No pending DCAs");
        if ($score) {
            //If the position is long, then the stop price should be triggered whenever the current price is below it, ergo
            //we look in the sorted set named "lte". Otherwise "gte".
            $keyName = 'LONG' === $this->side ? $this->lteKey : $this->gteKey;
            $queue = !empty($position->version) && 3 === $position->version ? 'exitPosition' : 'stopLoss';
            $keys[$keyName]["stopLoss:0:{$position->_id->__toString()}:$queue:16"] = $score;
            //$this->Monolog->sendEntry('debug', "Preparing stop loss for queue $queue");
        }
    }

    /**
     * Check if there is pending DCAs.
     * @param BSONDocument $position
     * @return bool
     */
    private function areAllDCATargetsDone(BSONDocument $position)
    {
        if (!isset($position->reBuyTargets) || !$position->reBuyTargets) {
            return true;
        }

        foreach ($position->reBuyTargets as $target) {
            if ((!isset($target->done) || !$target->done) && (!isset($target->cancel) || !$target->cancel)
                && (!isset($target->skipped) || !$target->skipped)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the stop loss price from a position
     * @param BSONDocument $position
     * @return bool|float|int
     */
    private function getStopLossPrice(BSONDocument $position)
    {
        if (!empty($position->stopLossPriority) && 'price' === $position->stopLossPriority && !empty($position->stopLossPrice)) {
            $stopLossPrice = round($position->stopLossPrice, 12, PHP_ROUND_HALF_DOWN);
        } else {
            $stopLossPrice = !is_numeric($position->stopLossPercentage) ? false
                : round($this->entryPrice * $position->stopLossPercentage, 12, PHP_ROUND_HALF_DOWN);
        }

        return $stopLossPrice;
    }

    /**
     * Update the keys array with members that need to be removed from stopLoss
     * @param BSONDocument $position
     * @param array $keys
     */
    private function getStopLossMemberNameForRemoving(BSONDocument $position, array & $keys)
    {
        //If the position is long, then the stop price should be triggered whenever the current price is below it, ergo
        //we look in the sorted set named "lte". Otherwise "gte".
        $keyName = 'LONG' === $this->side ? $this->lteKey : $this->gteKey;
        $queue = !empty($position->version) && 3 === $position->version ? 'exitPosition' : 'stopLoss';
        $keys[$keyName][] = "stopLoss:0:{$position->_id->__toString()}:$queue:16";
    }

    /**
     * Add the members from the keys to the redis sorted set.
     * @param array $keys
     */
    private function addMembersToKeys(array $keys)
    {
        if (!empty($keys[$this->gteKey])) {
            $this->RedisTriggersWatcher->addSortedSetPipeline($this->gteKey, $keys[$this->gteKey], 'CH');
        }
        if (!empty($keys[$this->lteKey])) {
            $this->RedisTriggersWatcher->addSortedSetPipeline($this->lteKey, $keys[$this->lteKey], 'CH');
        }
    }


    /**
     * Remove the members from the keys to the redis DB.
     * @param array $keys
     */
    private function removeMembersFromKeys(array $keys)
    {
        if (!empty($keys[$this->gteKey])) {
            $this->RedisTriggersWatcher->remMemberInSortedSetPipeline($this->gteKey, $keys[$this->gteKey]);
        }
        if (!empty($keys[$this->lteKey])) {
            $this->RedisTriggersWatcher->remMemberInSortedSetPipeline($this->lteKey, $keys[$this->lteKey]);
        }
    }
}

