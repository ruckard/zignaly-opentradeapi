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
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;

class PriceWatcher
{
    private $Monolog;
    private $newPositionCCXT;
    private $RabbitMQ;

    /**
     * @var RedisLockController
     */
    private $RedisLockController;

    /**
     * @var RedisHandler
     */
    private $RedisHandlerZignalyQueue;

    /**
     * @var PositionMediator
     */
    private $positionMediator;
    private $ExchangeCalls;

    public function __construct(
        Monolog & $Monolog,
        newPositionCCXT $newPositionCCXT,
        RabbitMQ $RabbitMQ,
        ExchangeCalls $ExchangeCalls
    ) {
        $container = DIContainer::getContainer();
        if ($container->has('monolog')) {
            $this->Monolog = $container->get('monolog');
        } else {
            $this->Monolog = $Monolog;
        }
        $this->RedisLockController = $container->get('RedisLockController');
        $this->RedisHandlerZignalyQueue = $container->get('redis.queue');
        $this->newPositionCCXT = $newPositionCCXT;
        $this->RabbitMQ = $RabbitMQ;
        $this->ExchangeCalls = $ExchangeCalls;
    }

    private function areAllDCATargetsDone($position)
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
     * @param float $trailingStopTriggerPrice
     * @param float|bool $extremePrice
     * @return bool
     */
    private function checkIfTrailingPriceIsTriggered(float $trailingStopTriggerPrice, $extremePrice)
    {
        if (!$extremePrice) {
            return true;
        }

        if ($this->positionMediator->isLong()) {
            return $trailingStopTriggerPrice >= $extremePrice;
        } else {
            return $trailingStopTriggerPrice <= $extremePrice;
        }
    }

    /**
     * It checks if a new trailingStopPrice needs to be updated.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @param float $trailingStopPrice
     * @return bool
     */
    private function checkIfStopPriceNeedsToBeUpdated(
        BSONDocument $position,
        float $trailingStopPrice
    ) {
        if (!$position->trailingStopPrice) {
            return true;
        }

        $trailingStopPriceFromPosition = is_object($position->trailingStopPrice) ? $position->trailingStopPrice->__toString() : $position->trailingStopPrice;
        if ($this->positionMediator->isLong() && $trailingStopPrice > $trailingStopPriceFromPosition) {
            return true;
        }
        if ($this->positionMediator->isShort() && $trailingStopPrice < $trailingStopPriceFromPosition) {
            return true;
        }
        return false;
    }

    /**
     * Check if the position targets have been triggered.
     * @param string $positionId
     * @param string $processName
     * @param int $timestamp
     * @return bool
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function checkPosition(string $positionId, string $processName, int $timestamp)
    {
        $position = $this->newPositionCCXT->getPosition($positionId);
        if (empty($position)) {
            $this->Monolog->sendEntry('ERROR', "Position $positionId doesn't exists");
            return true;
        }
        $this->Monolog->addExtendedKeys('userId', $position->user->_id->__toString());

        if ($position->closed) {
            //$this->Monolog->sendEntry('info', "Position already closed");
            return true;
        }

        $this->positionMediator = $this->createNewPositionMediator($position);
        if ($this->ordersTrigger($position)) {
            $position = $this->RedisLockController->positionHardLock($positionId, $processName);
            if (!$position) {
                $this->Monolog->sendEntry('debug', "Exiting because lock wasn't acquired.");
                return false;
            }
            $this->positionMediator->updatePositionEntity($position);
            if (!$this->ExchangeCalls->setCurrentExchange(
                $this->positionMediator->getExchange()->getId(),
                $this->positionMediator->getExchangeType(),
                $this->positionMediator->getExchangeIsTestnet()
            )) {
                $this->Monolog->sendEntry('critical', 'Error connecting the exchange');
                return false;
            } else {
                $CheckOrdersCCXT = new CheckOrdersCCXT(
                    $position,
                    $this->ExchangeCalls,
                    $this->newPositionCCXT,
                    $this->Monolog
                );

                $CheckOrdersCCXT->checkOrders(false, false, true);

                $position = $this->newPositionCCXT->getPosition($position->_id);
                $this->positionMediator->updatePositionEntity($position);

                if ($position->closed) {
                    return true;
                }
            }
        }

        if ($this->stopLossTrigger($position)) {
            //$this->Monolog->sendEntry('debug', 'Stop loss triggered');
            $newMessage = json_encode([
                'positionId' => $position->_id->__toString(),
                'status' => 16,
            ], JSON_PRESERVE_ZERO_FRACTION);
            $queueName = !empty($position->version) && 3 === $position->version ? 'exitPosition' : 'stopLoss';
            $queue = $queueName;
        } elseif ($this->trailingStopTrigger($position)) {
            //$this->Monolog->sendEntry('debug', 'Trailing top loss triggered');
            $newMessage = json_encode([
                'positionId' => $position->_id->__toString(),
                'status' => 18,
            ], JSON_PRESERVE_ZERO_FRACTION);
            $queueName = !empty($position->version) && 3 === $position->version ? 'exitPosition' : 'stopLoss';
            $queue = $queueName;
        }

        if (isset($newMessage)) {
            $this->sendMessageToQueue($newMessage, $queue, $position);
        }

        $position = $this->newPositionCCXT->getPosition($position->_id);
        if ($position->closed) {
            $key = empty($position->paperTrading) && empty($position->testNet) ? 'accountingQueue' : 'accountingQueue_Demo';
            $this->RedisHandlerZignalyQueue->addSortedSet($key, 0, $positionId);
        }
        return true;
    }


    /**
     * Create a PositionMediator handler from a position.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @return PositionMediator
     * @throws Exception
     */
    private function createNewPositionMediator(BSONDocument $position)
    {
        return PositionMediator::fromMongoPosition($position);
    }

    /**
     * Calculate stop loss price from position stop loss percentage setting.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @param bool|float $buyingPrice
     * @return bool|string
     */
    private function getStopLossPrice(BSONDocument $position, $buyingPrice)
    {
        if (!$buyingPrice || !is_numeric($buyingPrice)) {
            return false;
        }

        if (empty($position->stopLossPriority) || 'percentage' === $position->stopLossPriority) {
            if (false === $position->stopLossPercentage || !is_numeric($position->stopLossPercentage)) {
                return false;
            }
            $stopLossPrice = 0 === $position->stopLossPercentage ? $buyingPrice : $buyingPrice * $position->stopLossPercentage;
        } else {
            if (false === $position->stopLossPrice) {
                return false;
            }
            $stopLossPrice = $position->stopLossPrice;
        }

        if (!is_numeric($stopLossPrice)) {
            return false;
        }

        return number_format($stopLossPrice, 12, '.', '');
    }

    /**
     * Get the shorted since date for checking prices.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @param bool $trailingStop
     * @return bool|\MongoDB\BSON\UTCDateTime
     */
    private function getStopSince(BSONDocument $position, bool $trailingStop = true)
    {
        $stopLossPercentageLastUpdate = isset($position->stopLossPercentageLastUpdate) && $position->stopLossPercentageLastUpdate !== false
            ? $position->stopLossPercentageLastUpdate->__toString() : 0;
        $trailingStopLastUpdate = isset($position->trailingStopLastUpdate) && $position->trailingStopLastUpdate !== false
            ? $position->trailingStopLastUpdate->__toString() : 0;
        $buyPerformedAt = isset($position->buyPerformedAt) && $position->buyPerformedAt !== false
            ? $position->buyPerformedAt->__toString() : 0;
        $lastUpdate = isset($position->lastUpdate) && $position->lastUpdate !== false
            ? $position->lastUpdate->__toString() : 0;

        if ($trailingStop && $trailingStopLastUpdate) {
            return $trailingStopLastUpdate;
        }

        if (!$trailingStop && $stopLossPercentageLastUpdate) {
            return $stopLossPercentageLastUpdate;
        }

        if ($lastUpdate) {
            return $lastUpdate;
        }

        if ($buyPerformedAt) {
            return $buyPerformedAt;
        }

        return false;
    }


    /**
     * Get the optimal date for checking the position.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @return int|bool
     */
    private function getSince(BSONDocument $position)
    {
        $lastUpdate = isset($position->lastUpdate) ? $position->lastUpdate->__toString() : false;
        $buyPerformedAt = isset($position->buyPerformedAt) ? $position->buyPerformedAt->__toString() : false;

        if ($buyPerformedAt && $buyPerformedAt > $lastUpdate) {
            $since = $buyPerformedAt;
        } else {
            $since = $lastUpdate;
        }

        return $since;
    }

    /**
     * Calculate the trigger target price.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @return string
     */
    private function getTrailingStopTriggerPrice(BSONDocument $position)
    {
        if (!empty($position->trailingStopTriggerPriority) && 'price' === $position->trailingStopTriggerPriority
        && !empty($position->trailingStopTriggerPrice)) {
            return number_format($position->trailingStopTriggerPrice, 12, '.', '');
        } else {
            $entryPrice = $this->positionMediator->getAverageEntryPrice();

            return number_format($entryPrice * $position->trailingStopTriggerPercentage, 12, '.', '');
        }
    }

    /**
     * Given a price, check if the orders could have been triggered.
     *
     * @param BSONDocument $position
     * @return bool
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function ordersTrigger(BSONDocument $position)
    {
        if (empty($position->orders)) {
            return false;
        }

        $since = $this->getSince($position);
        foreach ($position->orders as $order) {
            if ('cancelled' === $order->status) {
                continue;
            }
            
            if (ExchangeOrderStatus::Expired === $order->status) {
                continue;
            }

            if (!isset($order->cost)) {
                $order->cost = 0;
            }

            if ($order->done && $order->cost > 0) {
                continue;
            }

            if (isset($order->orderType) && 'market' === strtolower($order->orderType)) {
                return true;
            }

            $orderPrice = isset($order->price) && is_numeric($order->price) && $order->price > 0 ? $order->price : false;
            if (!$orderPrice) {
                continue;
            }

            if ('MULTI' === $position->buyType && !empty($order->side) && 'sell' === $order->side && !empty($order->originalEntry)) {
                //We change the type so it's not compared with the lower price. Again, this is a mess because how getRecentLowerPrice works.
                $order->type = 'multiEntry';
            }

            if ('buy' === $order->type || 'entry' === $order->type || 'stop' === $order->type) {
                //The next getRecentLowerPrice is misleading. It applies the side, so it will return min for LONG but max for SHORT. We really should change this.
                $extremePrice = $this->positionMediator->getRecentLowerPrice($since);
                if ($this->positionMediator->isShort()) {
                    if ($extremePrice && $extremePrice >= $order->price) {
                        $this->Monolog->sendEntry('info', "Entry order with price {$order->price} triggered at price $extremePrice SHORT");
                        return true;
                    }
                } else {
                    if ($extremePrice && $extremePrice <= $order->price) {
                        $this->Monolog->sendEntry('info', "Entry order with price {$order->price} triggered at price $extremePrice LONG");
                        return true;
                    }
                }
            } else {
                //The next getRecentHigherPrice is misleading. It applies the side, so it will return max for LONG but min for SHORT. We really should change this.
                $extremePrice = $this->positionMediator->getRecentHigherPrice($since);
                if ($this->positionMediator->isShort()) {
                    if ($extremePrice && $extremePrice <= $order->price) {
                        $this->Monolog->sendEntry('info', "Exit order with price {$order->price} triggered at price $extremePrice SHORT");
                        return true;
                    }
                } else {
                    if ($extremePrice && $extremePrice >= $order->price) {
                        $this->Monolog->sendEntry('info', "Exit order with price {$order->price} triggered at price $extremePrice LONG");
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function sendMessageToQueue(
        string $message,
        string $queue,
        BSONDocument $position
    ) {
        global $newPositionCCXT, $RabbitMQ;

        if ($queue == 'reBuys') {
            $setPosition = [
                'increasingPositionSize' => true,
                'reBuyProcess' => true,
            ];
        } else {
            $setPosition = [
                'updating' => true,
                'lastUpdatingAt' => new \MongoDB\BSON\UTCDateTime(),
            ];
            $RabbitMQ->publishMsg($queue, $message);
        }

        if (isset($setPosition)) {
            $newPositionCCXT->setPosition($position->_id, $setPosition);
        }
    }

    /**
     * Check if the stop loss should be triggered.
     * @param BSONDocument $position
     * @return bool
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function stopLossTrigger(
        BSONDocument $position
    ) {
        if (9 !== $position->status) {
            return false;
        }

        if (!empty($position->sellingByStopLoss)) {
            return false;
        }

        if ($this->checkStopOrderExists($position)) {
            //$this->Monolog->sendEntry('info', "Stop order already placed in the exchange.");
            return false;
        }

        $targetPrice = $this->getStopLossPrice($position, $this->positionMediator->getAverageEntryPrice());
        if (!$targetPrice) {
            return false;
        }

        if (('LONG' === $this->positionMediator->getSide() && $targetPrice <= $this->positionMediator->getAverageEntryPrice()) ||
            ('SHORT' === $this->positionMediator->getSide() && $targetPrice >= $this->positionMediator->getAverageEntryPrice())) {
            if (!$this->areAllDCATargetsDone($position)) {
                return false;
            }
        }

        $since = $this->getStopSince($position, false);
        //The next getRecentLowerPrice is misleading. It applies the side, so it will return min for LONG but max for SHORT. We really should change this.
        $extremePrice = $this->positionMediator->getRecentLowerPrice($since);
        //$this->Monolog->sendEntry('debug', "Stop $targetPrice extreme price $extremePrice since $since");
        if ($this->positionMediator->isLong()) {
            if ($extremePrice && $extremePrice <= $targetPrice) {
                $this->Monolog->sendEntry('info', "StopLoss $targetPrice triggered at price $extremePrice LONG");
                return true;
            }
        } else {
            if ($extremePrice && $extremePrice >= $targetPrice) {
                $this->Monolog->sendEntry('info', "StopLoss $targetPrice triggered at price $extremePrice SHORT");
                return true;
            }
        }
    }

    /**
     * Check if stop market order placed in the exchange.
     * @param BSONDocument $position
     * @return bool
     */
    private function checkStopOrderExists(BSONDocument $position)
    {
        if (empty($position->orders)) {
            return false;
        }

        foreach ($position->orders as $order) {
            if ('stop' === $order->type && !$order->done) {
                return true;
            }
        }

        return false;
    }
    /**
     * Check if the trailing stop loss should be triggered.
     * @param BSONDocument $position
     * @return bool
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function trailingStopTrigger(BSONDocument $position)
    {
        if ($position->status != 9) {
            return false;
        }

        if (empty($position->trailingStopTriggerPercentage) && empty($position->trailingStopTriggerPrice)) {
            return false;
        }

        $since = $this->getStopSince($position);
        $position = $this->setTrailingStopTriggerPrice($position, $since);

        // When set trailing stop in the position with Mongo update fails, position result is false, prevent continue.
        if (!$position) {
            return false;
        }

        $this->positionMediator->updatePositionEntity($position);
        if (empty($position->trailingStopPrice)) {
            return false;
        }

        $since = $this->getStopSince($position);
        $targetPrice = is_object($position->trailingStopPrice) ? $position->trailingStopPrice->__toString() : $position->trailingStopPrice;
        //The next getRecentLowerPrice is misleading. It applies the side, so it will return min for LONG but max for SHORT. We really should change this.
        $extremePrice = $this->positionMediator->getRecentLowerPrice($since);
        if ($this->positionMediator->isLong()) {
            if ($extremePrice && $extremePrice <= $targetPrice) {
                $this->Monolog->sendEntry('info', "Trailing stop $targetPrice triggered at price $extremePrice LONG");
                return true;
            }
        } else {
            if ($extremePrice && $extremePrice >= $targetPrice) {
                $this->Monolog->sendEntry('info', "Trailing stop $targetPrice triggered at price $extremePrice SHORT");
                return true;
            }
        }

        return false;
    }

    /**
     * Set the trailing stop trigger price.
     * @param BSONDocument $position
     * @param $since
     * @return bool|\MongoDB\BSON\ObjectId|BSONDocument
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function setTrailingStopTriggerPrice(BSONDocument $position, $since)
    {
        try {
            if ((empty($position->trailingStopTriggerPercentage) && empty($position->trailingStopTriggerPrice)) || empty($position->trailingStopDistancePercentage)) {
                return $position;
            }
            $trailingStopTriggerPrice = $this->getTrailingStopTriggerPrice($position);
            $exchangeName = $this->positionMediator->getExchange()->getId();
            //The next getRecentLowerPrice is misleading. It applies the side, so it will return min for LONG but max for SHORT. We really should change this.
            list($extremePrice, $extremePriceTimestamp) = $this->positionMediator->getRecentHigherPrice($since, true);

            if (!$since || $this->checkIfTrailingPriceIsTriggered($trailingStopTriggerPrice, $extremePrice)) {
                return $position;
            }
            $exchangeAccountType = $this->positionMediator->getExchangeType();
            $isTestnet = $this->positionMediator->getExchangeIsTestnet();
            $exchangeConnected = $this->ExchangeCalls->setCurrentExchange($exchangeName, $exchangeAccountType, $isTestnet);
            if (!$exchangeConnected) {
                $this->Monolog->sendEntry('critical', 'Error connecting the exchange');
                return $position;
            }
            $trailingStopPrice = $this->ExchangeCalls->getPriceToPrecision($extremePrice *
                $position->trailingStopDistancePercentage, $this->positionMediator->getSymbol());
            if ($this->checkIfStopPriceNeedsToBeUpdated($position, $trailingStopPrice)) {
                $this->Monolog->sendEntry('debug', "Updating Trailing Stop Price: $trailingStopPrice");
                $setPosition = [
                    'trailingStopPrice' => (float)($trailingStopPrice),
                    'trailingStopLastUpdate' => new \MongoDB\BSON\UTCDateTime($extremePriceTimestamp),
                ];

                return $this->newPositionCCXT->setPosition($position->_id, $setPosition, false);
            }
        } catch (Exception $e) {
            $this->Monolog->sendEntry('CRITICAL', 'Updating the trailing stop price: ' . $e->getMessage());

            return $position;
        }

        return $position;
    }
}
