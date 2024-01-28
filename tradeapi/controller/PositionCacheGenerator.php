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

class PositionCacheGenerator
{
    /**
     * @var Accounting
     */
    private $Accounting;

    /**
     * @var Monolog
     */
    private $Monolog;

    /**
     * newPositionCCXT model
     *
     * @var \newPositionCCXT
     */
    private $newPositionCCXT;

    /**
     * RedisHandler service
     *
     * @var \RedisHandler
     */
    private $RedisHandlerZignalyQueue;

    /**
     * @var RedisHandler
     */
    //private $RedisOpenPositionsCache;

    /**
     * @var Status
     */
    private $Status;

    public function __construct()
    {
        $container = DIContainer::getContainer();
        if ($container->has('monolog')) {
            $this->Monolog = $container->get('monolog');
        } else {
            $container->set('monolog', new Monolog('positionsCacheGenerator'));
            $this->Monolog = $container->get('monolog');
        }

        $this->newPositionCCXT = $container->get('newPositionCCXT.model');
        $this->newPositionCCXT->configureLoggingByContainer($container);
        $this->RedisHandlerZignalyQueue = $container->get('redis.queue');
        //$this->RedisOpenPositionsCache = $container->get('cache.openPositions');
        $this->Accounting = $container->get('accounting');
        $this->Status = $container->get('position.status');
    }

    /**
     * Compose the set key for a open position from the position object
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @return bool|string
     */
    /*private function composeKeyForOpenPositions(\MongoDB\Model\BSONDocument $position)
    {
        if (empty($position->user) || empty($position->user->_id)) {
            return false;
        } else {
            $userId = $position->user->_id->__toString();
        }

        if (empty($position->exchange) || empty($position->exchange->internalId)) {
            return false;
        } else {
            $internalExchangeId = $position->exchange->internalId;
        }

        return 'openPositions:' . $userId . ':' . $internalExchangeId;
    }*/

    /**
     * Count the targets if any and return the status of each one.
     *
     * @param object|bool $targets
     * @return array
     */
    private function countTargetsStatus($targets)
    {
        $fail = 0;
        $success = 0;
        $pending = 0;

        if ($targets) {
            foreach ($targets as $target) {
                if ((isset($target->cancel) && $target->cancel) || (isset($target->skipped) && $target->skipped))
                    $fail++;
                elseif (isset($target->done) && $target->done)
                    $success++;
                else
                    $pending++;
            }
        }

        return [
            'fail' => $fail,
            'success' => $success,
            'pending' => $pending,
        ];
    }

    /**
     * Parse the given position to an array for sending to Redis
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @return array
     */
    public function composePositionForCache(\MongoDB\Model\BSONDocument $position)
    {
        $buyPrice = is_object($position->avgBuyingPrice) ? $position->avgBuyingPrice->__toString() : $position->avgBuyingPrice;
        list($amount, $remainAmount) = $this->Accounting->recalculateAndUpdateAmounts($position);
        $providerId = isset($position->signal->providerId) ? $position->signal->providerId : false;
        if (is_object($providerId)) {
            $providerId = $providerId->__toString();
        }

        $takeProfitTargetsCount = !empty($position->takeProfitTargets)
            ? $this->countTargetsStatus($position->takeProfitTargets) : false;
        $reBuyTargetsCount = !empty($position->reBuyTargets) ? $this->countTargetsStatus($position->reBuyTargets)
            : false;

        list($grossProfitsFromExitAmount, $exitedAmount) = $this->Accounting->computeGrossProfitFromExitedAmount($position);
        $lockedAmount = $this->Accounting->getLockedAmountFromPosition($position);
        $lockedPendingInvestment = $this->Accounting->getLockedInvestmentFromEntries($position);

        list($currentAllocatedBalance, $positionSizePercentage) = $this->getCopyTradingAllocationData($position);

        $leverage = isset($position->leverage) && $position->leverage > 0? $position->leverage: 1;

        // add units to position
        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
        list($originalEntryPrice, $originalInvestedAmount) = $this->Accounting->getEntryPrice($position);

        list ($stopLossPrice, $stopLossPercentage) = $positionMediator->getStopLossPriceAndPercentage($buyPrice);
        list ($trailingStopTriggerPrice, $trailingStopTriggerPercentage) = $positionMediator->getTrailingStopTriggerPriceAndPercentage($buyPrice);


        $positionToBeReturned = [
            'positionId' => $position->_id->__toString(),
            'exchange' => !empty($position->exchange->name) ? $position->exchange->name : '',
            'exchangeType' => $positionMediator->getExchangeType(),
            'internalExchangeId' => !empty($position->exchange->internalId) ? $position->exchange->internalId : '',
            'openDate' => $position->signal->datetime->__toString(),
            'pair' => $positionMediator->getSymbol(),
            'base' => !empty($position->signal->base) ? $position->signal->base : 'UNKNOWN',
            'quote' => !empty($position->signal->quote) ? $position->signal->quote : 'UNKNOWN',
            'buyPrice' => $buyPrice,
            'side' => $this->getSide($position),
            'multiData' => $this->composeMultiEntriesData($position),
            'amount' => $amount,
            'remainAmount' => $remainAmount,
            'availableAmount' => $amount - $lockedAmount,
            'lockedPendingInvestment' => $lockedPendingInvestment,
            //'positionSizeQuote' => $buyPrice * $amount,
            'positionSizeQuote' => $exchangeHandler->calculatePositionSize(
                $positionMediator->getSymbol(),
                $amount,
                $buyPrice
            ),
            'stopLossPriority' => $positionMediator->getStopLossPriority(),
            'stopLossPercentage' => $stopLossPercentage,
            'stopLossFollowsTakeProfit' => !empty($position->stopLossFollowsTakeProfit),
            'stopLossToBreakEven' => !empty($position->stopLossToBreakEven),
            'stopLossPrice' => $stopLossPrice,
            'stopLossOrderId' => $this->getStopLossOrderId($position),
            'trailingStopTriggered' => !empty($position->trailingStopPrice),
            'trailingStopTriggerPriority'=> $positionMediator->getTrailingStopTriggerPriority(),
            'trailingStopTriggerPercentage' => $trailingStopTriggerPercentage,
            'trailingStopTriggerPrice' => $trailingStopTriggerPrice,
            'trailingStopPercentage' => empty($position->trailingStopPercentage) ? false : $position->trailingStopPercentage * 100 - 100,
            'status' => $position->status,
            'updating' => !empty($position->updating),
            'providerId' => $providerId,
            'providerName' => isset($position->signal->providerName) ? $position->signal->providerName : false,
            'takeProfitTargetsCountFail' => $takeProfitTargetsCount ? $takeProfitTargetsCount['fail'] : 0,
            'takeProfitTargetsCountSuccess' => $takeProfitTargetsCount ? $takeProfitTargetsCount['success'] : 0,
            'takeProfitTargetsCountPending' => $takeProfitTargetsCount ? $takeProfitTargetsCount['pending'] : 0,
            'reBuyTargetsCountFail' => $reBuyTargetsCount ? $reBuyTargetsCount['fail'] : 0,
            'reBuyTargetsCountSuccess' => $reBuyTargetsCount ? $reBuyTargetsCount['success'] : 0,
            'reBuyTargetsCountPending' => $reBuyTargetsCount ? $reBuyTargetsCount['pending'] : 0,
            'isCopyTrading' => !empty($position->provider->isCopyTrading),
            'isCopyTrader' => !empty($position->provider->isCopyTrading) && isset($position->signal->userId)
                && $position->user->_id->__toString() == $position->signal->userId,
            'signalId' => isset($position->signal->signalId) ? $position->signal->signalId : false,
            'realInvestment' => $exchangeHandler->calculatePositionSize(
                $positionMediator->getSymbol(),
                $amount,
                $buyPrice
            )
                / $leverage,
            'leverage' => $leverage,
            'grossProfitsFromExitAmount' => $grossProfitsFromExitAmount,
            'exitedAmount' => $exitedAmount,
            'positionSizePercentage' => $positionSizePercentage,
            'currentAllocatedBalance' => $currentAllocatedBalance,
            'originalEntryPrice' => $originalEntryPrice,
            'originalInvestedAmount' => $originalInvestedAmount,
            //unrealizedProfitLosses
            //profitPercentage
            //providerLogo
            //sellPrice
        ];
        return array_merge(
            $positionToBeReturned,
            $positionMediator->getExtraSymbolsAsArray()
        );
    }

    /**
     * Return the order id of the stop loss order placed in the exchange.
     * @param \MongoDB\Model\BSONDocument $position
     * @return bool|string
     */
    private function getStopLossOrderId(\MongoDB\Model\BSONDocument $position)
    {
        if (empty($position->order)) {
            return false;
        }

        foreach ($position->order as $order) {
            if ('stop' === $order->type && false === $order->done) {
                return $order->orderId;
            }
        }

        return false;
    }

    /**
     * Compose multiData
     * @param \MongoDB\Model\BSONDocument $position
     * @return array
     */
    private function composeMultiEntriesData(\MongoDB\Model\BSONDocument $position)
    {
        if ('MULTI' !== $position->buyType || $position->status > 1) {
            return [];
        }

        $firstPrice = empty($position->multiFirstData->limitPrice) ? '' : $position->multiFirstData->limitPrice;
        $firstAmount = empty($position->multiFirstData->amount) ? '' : $position->multiFirstData->amount;
        $secondPrice = empty($position->multiSecondData->limitPrice) ? '' : $position->multiSecondData->limitPrice;
        $secondAmount = empty($position->multiSecondData->amount) ? '' : $position->multiSecondData->amount;
        return [
            'long' => [
                'price' => $firstPrice,
                'amount' => $firstAmount,
            ],
            'short' => [
                'price' => $secondPrice,
                'amount' => $secondAmount,
            ]
        ];
    }


    /**
     * Return the side of the position.
     * @param \MongoDB\Model\BSONDocument $position
     * @return string
     */
    private function getSide(\MongoDB\Model\BSONDocument $position)
    {
        if (1 === $position->status || 0 === $position->status) {
            if ('MULTI' === $position->buyType) {
                return 'MULTI';
            }
        }

        return !empty($position->side) ? $position->side : 'LONG';
    }

    public function composeSoldPositionForCache(\MongoDB\Model\BSONDocument $position)
    {
        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

        if (empty($position->profitSharingData)) {
            if (empty($position->provider->allocatedBalance)) {
                $allocatedBalance = 0;
            } else {
                $allocatedBalance =  is_object($position->provider->allocatedBalance)? $position->provider->allocatedBalance->__toString() : $position->provider->allocatedBalance;
            }

            if (empty($position->provider->profitsFromClosedBalance)) {
                $profitsFromClosedBalance = 0;
            } else {
                $profitsFromClosedBalance = is_object($position->provider->profitsFromClosedBalance) ? $position->provider->profitsFromClosedBalance->__toString() : $position->provider->profitsFromClosedBalance;
            }

            $currentAllocatedBalance = $allocatedBalance + $profitsFromClosedBalance;
        } else {
            $currentAllocatedBalance = $position->profitSharingData->sumUserAllocatedBalance;
        }
        if (empty($position->accounting->fundingFees)) {
            $fundingFees = 0;
        } else {
            $fundingFees = is_object($position->accounting->fundingFees) ? $position->accounting->fundingFees->__toString() : $position->accounting->fundingFees;
        }
        $buyTotalQty = is_object($position->accounting->buyTotalQty) ? $position->accounting->buyTotalQty->__toString() : $position->accounting->buyTotalQty;
        $buyAvgPrice = is_object($position->accounting->buyAvgPrice) ? $position->accounting->buyAvgPrice->__toString() : $position->accounting->buyAvgPrice;
        $investment = $exchangeHandler->calculatePositionSize($positionMediator->getSymbol(), $buyTotalQty, $buyAvgPrice);
        $netProfit = is_object($position->accounting->netProfit) ? $position->accounting->netProfit->__toString() : $position->accounting->netProfit;
        $totalFees = is_object($position->accounting->totalFees) ? $position->accounting->totalFees->__toString() : $position->accounting->totalFees;
        $grossProfit = $netProfit + $totalFees - $fundingFees;

        // add units to position
        $positionMediator = PositionMediator::fromMongoPosition($position);

        $leverage = isset($position->leverage) && $position->leverage > 0? $position->leverage: 1;

        $buyPrice = is_object($position->avgBuyingPrice) ? $position->avgBuyingPrice->__toString() : $position->avgBuyingPrice;

        list ($stopLossPrice, $stopLossPercentage) = $positionMediator->getStopLossPriceAndPercentage($buyPrice);
        list ($trailingStopTriggerPrice, $trailingStopTriggerPercentage) = $positionMediator->getTrailingStopTriggerPriceAndPercentage($buyPrice);

        $positionToBeReturned = [
            'positionId' => $position->_id->__toString(),
            'userId' => $position->user->_id->__toString(),
            'openDate' => isset($position->accounting) ? $position->accounting->openingDate->__toString() : $position->createdAt->__toString(),
            'openTrigger' => 'Signal', //Todo: Do it dynamically.
            'closeDate' => isset($position->accounting) ? $position->accounting->closingDate->__toString() : false,
            'closeTrigger' => 'Auto', //Todo: Do it dynamically.
            'pair' => $positionMediator->getSymbol(), //$position->signal->base . '/' . $position->signal->quote,
            'base' => $position->signal->base,
            'quote' => $position->signal->quote,
            'buyPrice' => is_object($position->accounting->buyAvgPrice) ? $position->accounting->buyAvgPrice->__toString() : $position->accounting->buyAvgPrice,
            'sellPrice' => is_object($position->accounting->sellAvgPrice) ? $position->accounting->sellAvgPrice->__toString() : $position->accounting->sellAvgPrice,
            'side' => isset($position->side) ? $position->side : 'LONG',
            'amount' => is_object($position->accounting->buyTotalQty) ? $position->accounting->buyTotalQty->__toString() : $position->accounting->buyTotalQty,
            'remainAmount' => 0,
            'invested' => $investment,
            'positionSize' => $investment,
            'investedQuote' => $position->signal->quote,
            'positionSizeQuote' => $investment,
            'profitPercentage' => $grossProfit * 100 / $investment,
            'profit' => $grossProfit,
            'netProfitPercentage' => ($netProfit * 100 / $investment) * $leverage,
            'netProfit' => $netProfit,
            'fees' => $totalFees * -1,
            'fundingFees' => $fundingFees,
            'quoteAsset' => $position->signal->quote,
            'stopLossPriority' => $positionMediator->getStopLossPriority(),
            'stopLossPercentage' => $stopLossPercentage,
            'stopLossPrice' => $stopLossPrice,
            'takeProfit' => $position->exchange && isset($position->exchange->takeProfit) ? $position->exchange->takeProfit : false,
            'trailingStopPercentage' => $trailingStopTriggerPercentage,
            'trailingStopTriggerPriority'=> $positionMediator->getTrailingStopTriggerPriority(),
            'trailingStopTriggerPercentage' => $trailingStopTriggerPercentage,
            'trailingStopTriggerPrice' => $trailingStopTriggerPrice,
            'trailingStopTriggered' => !empty($position->trailingStopPrice),
            'exchange' => $position->exchange ? $position->exchange->name : false,
            'exchangeType' => $positionMediator->getExchangeType(),
            'exchangeInternalName' => !empty($position->exchange->internalName) ? $position->exchange->internalName : $position->exchange->name,
            'symbol' => $positionMediator->getSymbol(), //$position->signal->base . $position->signal->quote,
            'status' => $position->status,
            'statusDesc' => $this->Status->getPositionStatusText($position->status),
            'sellPlaceOrderAt' => (isset($position->sellPlaceOrderAt)) ? $position->sellPlaceOrderAt : '',
            'checkStop' => $position->checkStop,
            'provider' => (isset($position->signal->providerName)) ? $position->signal->providerName : 'Unknown',
            'sellByTTL' => isset($position->sellByTTL) ? $position->sellByTTL : false,
            'buyTTL' => isset($position->buyTTL) ? $position->buyTTL : false,
            'takeProfitTargets' => isset($position->takeProfitTargets) ? $position->takeProfitTargets : false,
            'orders' => isset($position->orders) ? $position->orders : false,
            'reBuyTargets' => isset($position->reBuyTargets) ? $position->reBuyTargets : false,
            'closed' => $position->closed,
            'updating' => false,
            'signalMetadata' => isset($position->signal->metadata) ? $position->signal->metadata : false,
            'accounting' => isset($position->accounting) ? $position->accounting : false,
            'providerId' => $position->provider->_id,
            'providerName' => isset($position->signal->providerName) ? $position->signal->providerName : false,
            'signalTerm' => isset($position->signal->term) ? $position->signal->term : "-",
            'signalId' => isset($position->signal->signalId) ? $position->signal->signalId : false,
            'takeProfitTargetsCountFail' => 0,
            'takeProfitTargetsCountSuccess' => $this->newPositionCCXT->countFilledTargets($position->takeProfitTargets, $position->orders),
            'takeProfitTargetsCountPending' => 0,
            'reBuyTargetsCountFail' => 0,
            'reBuyTargetsCountSuccess' => $this->newPositionCCXT->countFilledTargets($position->reBuyTargets, $position->orders),
            'reBuyTargetsCountPending' => 0,
            'isCopyTrading' => !empty($position->provider->isCopyTrading),
            'isCopyTrader' => !empty($position->provider->isCopyTrading) && isset($position->signal->userId) && $position->user->_id->__toString() == $position->signal->userId,
            'profitSharing' => !empty($position->profitSharingData),
            'type' => 'sold',
            'copyTraderId' => !empty($position->provider->isCopyTrading) ? $position->user->_id->__toString() : false,
            'paperTrading' => !empty($position->paperTrading),
            'realInvestment' => $investment / $leverage,
            'leverage' => $leverage,
            'internalExchangeId' => isset($position->exchange) && isset($position->exchange->internalId)
                ? $position->exchange->internalId : false,
            'positionSizePercentage' => empty($position->signal->positionSizePercentage) ? false : $position->signal->positionSizePercentage,
            'currentAllocatedBalance' => empty($currentAllocatedBalance) ? false : $currentAllocatedBalance,
        ];
        return array_merge(
            $positionToBeReturned,
            $positionMediator->getExtraSymbolsAsArray()
        );
    }

    /**
     * Given the current position return the allocation data if is from a copy-trader.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @return array
     */
    private function getCopyTradingAllocationData(\MongoDB\Model\BSONDocument $position)
    {
        $currentAllocatedBalance = false;
        $positionSizePercentage = false;
        if (!empty($position->provider->isCopyTrading)) {
            if (empty($position->provider->allocatedBalance)) {
                $allocatedBalance = 0;
            } else {
                $allocatedBalance = is_object($position->provider->allocatedBalance) ? $position->provider->allocatedBalance->__toString() : $position->provider->allocatedBalance;
            }
            if (empty($position->provider->profitsFromClosedBalance)) {
                $profitsFromClosedBalance = 0;
            } else {
                $profitsFromClosedBalance = is_object($position->provider->profitsFromClosedBalance) ? $position->provider->profitsFromClosedBalance->__toString() : $position->provider->profitsFromClosedBalance;
            }

            $currentAllocatedBalance = $allocatedBalance + $profitsFromClosedBalance;
            list(, $initialInvestment) = $this->Accounting->getEntryPrice($position);
            if (!empty($initialInvestment)) {
                $positionSizePercentage = $currentAllocatedBalance == 0 ? 0 : $initialInvestment * 100 / $currentAllocatedBalance;
            }
        }

        return [$currentAllocatedBalance, $positionSizePercentage];
    }

    /**
     * Retrieve the open positions for a given user and internal exchange.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @return array|bool
     */
    /*public function getOpenPositions(string $userId, string $internalExchangeId)
    {
        $key = 'openPositions:' . $userId . ':' . $internalExchangeId;
        $positions = $this->RedisOpenPositionsCache->getHashAll($key);

        $openPositionsIdsFromDB = $this->newPositionCCXT->getPositionsIdFromGivenList($userId, $internalExchangeId);

        if (empty($positions) && empty($openPositionsIdsFromDB)) {
            return false;
        }

        list($openPositions, $positionsIds) = $this->decodeAndExtractPositionsId($positions);

        $openPositions = $this->syncPositionsBetweenMongoAndRedis($positionsIds, $openPositionsIdsFromDB, $openPositions, $key);

        $returnPositions = [];
        foreach ($openPositions as $openPosition) {
            if (!in_array($openPosition['positionId'], $openPositionsIdsFromDB)) {
                continue;
            } else {
                $returnPositions[] = $openPosition;
            }
        }

        return $returnPositions;
    }*/

    /**
     * Check that positions from redis are still opened and that open positions in the db are in Redis.
     *
     * @param array $redisPositions
     * @param array $mongoPositions
     * @param array $returnPositions
     * @param string $key
     * @return array
     */
    /*private function syncPositionsBetweenMongoAndRedis(
        array $redisPositions,
        array $mongoPositions,
        array $returnPositions,
        string $key
    ) {
        $positionsNotInMongo = array_diff($redisPositions, $mongoPositions);

        if (!empty($positionsNotInMongo)) {
            foreach ($positionsNotInMongo as $positionId) {
                $this->RedisHandlerZignalyQueue->addSortedSet('removeFromCacheAndQueues', time(), $positionId);
            }
        }

        $positionsNotInRedis = array_diff($mongoPositions, $redisPositions);
        if (!empty($positionsNotInRedis)) {
            foreach ($positionsNotInRedis as $positionId) {
                $this->sendOpenPositionToCache($this->newPositionCCXT->getPosition($positionId));
            }
            $positions = $this->RedisOpenPositionsCache->getHashAll($key);
            list($returnPositions,) = $this->decodeAndExtractPositionsId($positions);
        }

        return $returnPositions;
    }*/

    /**
     * Extract the positions id from the given array.
     *
     * @param array $positionsFromCache
     * @return array
     */
    /*private function decodeAndExtractPositionsId(array $positionsFromCache)
    {
        $ids = [];
        $openPositions = [];
        foreach ($positionsFromCache as $position) {
            $openPosition = json_decode($position, true);
            $openPositions[] = $openPosition;
            $ids[] = $openPosition['positionId'];
        }

        return [$openPositions, $ids];
    }*/

    /**
     * Remove the given position from the cache.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @return bool|int
     */
    /*public function removePositionFromCache(\MongoDB\Model\BSONDocument $position)
    {
        $key = $this->composeKeyForOpenPositions($position);
        if (!$key) {
            return false;
        }

        $positionId = $position->_id->__toString();

        return $this->RedisOpenPositionsCache->removeHashMember($key, $positionId);
    }*/

    /**
     * Given an updated position removed it from the cache if it's closed or update it.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @return bool|int
     */
    /*public function sendOpenPositionToCache(\MongoDB\Model\BSONDocument $position)
    {
        $key = $this->composeKeyForOpenPositions($position);
        if (!$key) {
            return false;
        }
        $positionId = $position->_id->__toString();
        if ($position->closed) {
            return $this->RedisOpenPositionsCache->removeHashMember($key, $positionId);
        } else {
            $parsedPosition = $this->composePositionForCache($position);
            return $this->RedisOpenPositionsCache->setHash($key, $positionId, json_encode($parsedPosition));
        }
    }*/
}
