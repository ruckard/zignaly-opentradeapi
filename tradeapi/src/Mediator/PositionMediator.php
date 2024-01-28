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


namespace Zignaly\Mediator;

use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;
use Zignaly\exchange\ExchangeFactory;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\ExchangeMediator\ExchangeMediator;
use Zignaly\Mediator\ExchangeMediator\NoExchangeMediator;
use Zignaly\Process\DIContainer;

/**
 * Position mediator simplify the usage of position related services.
 *
 * Support the collaboration of position exchange, last prices and recent prices,
 * with an interface easy to consume within the context of a position.
 * Also provides some position related utilities that when grow to a reasonable
 * size we will move to position utilities service.
 *
 * @package Zignaly\Mediator
 */
class PositionMediator
{

    /**
     * @var \Zignaly\redis\ZignalyLastTradesRedisService
     */
    protected $recentHistoryPrices;

    /**
     * @var \Zignaly\redis\ZignalyLastPriceRedisService
     */
    protected $lastPrices;

    /**
     * @var \Zignaly\exchange\BaseExchange
     */
    protected $exchange;

    /**
     * @var \MongoDB\Model\BSONDocument
     */
    protected $positionEntity;

    /**
     * Process memory cache.
     *
     * @var \Symfony\Component\Cache\Adapter\ArrayAdapter|null
     */
    protected $arrayCache;

    /**
     * Exchange Mediator
     *
     * @var ExchangeMediator
     */
    protected $exchangeMediator;

    public function __construct(Object $position)
    {
        $container = DIContainer::getContainer();
        $this->positionEntity = $position;

        if (isset($position->exchange)) {
            if (!is_object($position->exchange)) {
                throw new \Exception(
                    sprintf(
                        "Position doesn't contain a valid exchange object: %s",
                        json_encode($position, JSON_PRETTY_PRINT)
                    )
                );
            }

            if (!$position->exchange->name) {
                throw new \Exception(
                    sprintf(
                        "Position exchange name is invalid: %s",
                        json_encode($position, JSON_PRETTY_PRINT)
                    )
                );
            }

            $psExchangeData = null;


            $this->exchangeMediator = ExchangeMediator::
                fromMongoExchange($position->exchange, $psExchangeData);
        } else {
            $this->exchangeMediator = new NoExchangeMediator();
        }

        $exchangeType = $this->exchangeMediator->getExchangeType();
        // TODO: If you invoke any endpoint in this exchange object
        // some memory could not be released (memory leak)
        $this->exchange = ExchangeFactory::createFromNameAndType(
            $this->exchangeMediator->getName(),
            $exchangeType,
            []
        );
        $this->lastPrices = $container->get('lastPrice');
        $this->recentHistoryPrices = $container->get('recentHistoryPrices');
        $this->arrayCache = $container->get('arrayCache');
        $this->arrayCache->clear(); //This cleaning is super important, we don't want to keep the cache from one cycle to another.
    }

    /**
     * Creates an exchange position for right exchange
     *
     * @param \MongoDB\Model\BSONDocument $position zignaly position
     * 
     * @return PositionMediator
     */
    public static function fromMongoPosition($position): PositionMediator
    {
        $psExchangeData = null;


        $exchangeMediator = ExchangeMediator::fromMongoExchange($position->exchange, $psExchangeData);
        if (ZignalyExchangeCodes::isBitmex($exchangeMediator->getName())) {
            return new BitmexPositionMediator($position);
        } else {
            return new PositionMediator($position);
        }
    }

    /**
     * Get exchange mediator
     *
     * @return ExchangeMediator
     */
    public function getExchangeMediator() : ExchangeMediator
    {
        return $this->exchangeMediator;
    }

    /**
     * Get the total entry amount (or the intended amount in the entry order sent to the exchange but not filled yet).
     *
     * @return float|init
     */
    public function getAmount()
    {
        if ($this->positionEntity->status == 1 || $this->positionEntity->status == 0)
            $amount = is_object($this->positionEntity->amount) ? $this->positionEntity->amount->__toString() : $this->positionEntity->amount;
        else
            $amount = is_object($this->positionEntity->realAmount) ? $this->positionEntity->realAmount->__toString() : $this->positionEntity->realAmount;

        return $amount;
    }

    /**
     * Check if the position has been opened with hedgeMode enabled.
     *
     * @return bool
     */
    public function checkIfHedgeMode() : bool
    {
        return !empty($this->positionEntity->signal->hedgeMode);
    }

    /**
     * Get the average entry price
     *
     * @return mixed
     */
    public function getAverageEntryPrice()
    {
        return is_object($this->positionEntity->avgBuyingPrice) ? $this->positionEntity->avgBuyingPrice->__toString() : $this->positionEntity->avgBuyingPrice;
    }

    /**
     * Get the position base.
     *
     * @return string
     */
    public function getBase()
    {
        if (isset($this->positionEntity->signal->base)) {
            return $this->positionEntity->signal->base;
        }
        return "UNKNOWN";
    }

    /**
     * Get the position id.
     *
     * @return ObjectId
     */
    public function getPositionId()
    {
        return $this->positionEntity->_id;
    }

    /**
     * Get the position quote.
     *
     * @return string
     */
    public function getQuote()
    {
        if (isset($this->positionEntity->signal->quote)) {
            return $this->positionEntity->signal->quote;
        }
        return "UNKNOWN";
    }

    /**
     * Get the position symbol.
     *
     * @return string
     */
    public function getSymbol()
    {
        if (isset($this->positionEntity->signal->base) 
            && isset($this->positionEntity->signal->quote)
        ) {
            return $this->positionEntity->signal->base 
                . $this->positionEntity->signal->quote;
        }
        return "UNKNOWN";
    }

    /**
     * Get the position symbol.
     *
     * @return string
     */
    public function getSymbolWithSlash()
    {
        if (isset($this->positionEntity->signal->base) 
            && isset($this->positionEntity->signal->quote)
        ) {
            return $this->positionEntity->signal->base . '/' . $this->positionEntity->signal->quote;
        }
        return "UNKNOWN";
    }

    /**
     * Get CCXT symbol
     *
     * @return string
     */
    public function getCcxtSymbol()
    {
        return $this->exchangeMediator->getMarketEncoder()
            ->toCcxt($this->positionEntity->signal->pair);
    }

    /**
     * Get CCXT object for the position exchange.
     *
     * @return \Zignaly\exchange\BaseExchange
     */
    public function getExchange()
    {
        return $this->exchange;
    }

    /**
     * Get exchange internal ID.
     *
     * @return string
     */
    public function getExchangeInternalId()
    {
        return $this->positionEntity->exchange->internalId;
    }

    /**
     * Get the position side.
     *
     * @return string The side SHORT|LONG.
     */
    public function getSide()
    {
        // Fallback to LONG when undefined.
        if (!isset($this->positionEntity->side)) {
           return 'LONG';
        }

       return $this->positionEntity->side;
    }

    /**
     * Check if position is short.
     *
     * @return bool When is short returns TRUE.
     */
    public function isShort()
    {
        return $this->getSide() === 'SHORT';
    }

    /**
     * Check if position is long.
     *
     * @return bool When is long returns TRUE.
     */
    public function isLong()
    {
        return $this->getSide() === 'LONG';
    }

    /**
     * Get the last exchange price for the position symbol.
     *
     * @return bool|string
     */
    public function getLastPrice()
    {
        // Use resolved exchange that considers exchange type.
        return $this->lastPrices->lastPriceStrForSymbol(
            $this->exchange->getId(),
            $this->getSymbol()
        );
    }

    /**
     * Get if the position is using a testnet exchange account.
     *
     * @return bool
     */
    public function getExchangeIsTestnet()
    {
        return $this->exchangeMediator->isTestnet();
    }

    /**
     * Get the position exchange type.
     *
     * @return string
     */
    public function getExchangeType()
    {
        return $this->exchangeMediator->getExchangeType();
    }

    /**
     * Get the lower exchange price for the position symbol from recent prices.
     *
     * @param string $type Indicate the extreme price to look (min or max).
     * @param int $since Unix timestamp since when to lookup the price.
     *
     * @return array Extreme price and timestamp when it happened.
     * @throws \Exception When extreme lookup type is invalid.
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function getRecentRelativeExtremePrice($since, $type)
    {
        $extremePrice = false;
        $extremePriceTimestamp = false;
        // Build price cache key.
        $positionId = $this->positionEntity->_id->__toString();
        $cacheKey = "{$type}Price_{$positionId}_{$since}";
        $cache = $this->arrayCache->getItem($cacheKey);

        // Price solved from cache.
        if ($cache->isHit()) {
            return json_decode($cache->get());
        }

        if (($this->isLong() && $type === 'max') || ($this->isShort() && $type === 'min'))  {
            list($extremePrice, $extremePriceTimestamp) = $this->recentHistoryPrices->getHigherPrice(
                $this->exchange->getId(),
                $this->getSymbol(),
                $since,
                true
            );
        }

        if (($this->isLong() && $type === 'min') || ($this->isShort() && $type === 'max'))  {
            list($extremePrice, $extremePriceTimestamp) = $this->recentHistoryPrices->getLowerPrice(
                $this->exchange->getId(),
                $this->getSymbol(),
                $since,
                true
            );
        }

        // If valid type is passed should never reach here.
        if (false === $extremePrice) {
            throw new \Exception(
                sprintf(
                    "Relative extreme price lookup not implemented for '%s' - '%s'",
                    $this->getSide(), $type
                )
            );
        }

        // Save price into cache when available.
        if (null !== $extremePrice) {
            $cache->set(json_encode([$extremePrice, $extremePriceTimestamp]));
            $this->arrayCache->save($cache);
        }

        return [$extremePrice, $extremePriceTimestamp];
    }

    /**
     * Get the lower exchange price for the position symbol from recent prices.
     *
     * @param int $since Unix timestamp since when to lookup the price.
     * @param bool $withTimestamp
     *
     * @return float|array The lower price and optionaly the timestamp when it was registered.
     * @throws \Exception When relative extreme price receives invalid type.
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getRecentLowerPrice($since, $withTimestamp = false)
    {
        list($extremePrice, $extremePriceTimestamp) = $this->getRecentRelativeExtremePrice($since, 'min');

        return $withTimestamp ? [$extremePrice, $extremePriceTimestamp] : $extremePrice;
    }

    /**
     * Get the higher exchange price for the position symbol from recent prices.
     *
     * @param int $since Unix timestamp since when to lookup the price.
     * @param bool $withTimestamp
     *
     * @return float|array The lower price and optionaly the timestamp when it was registered.
     * @throws \Exception When relative extreme price receives invalid type.
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getRecentHigherPrice($since, $withTimestamp = false)
    {
        list($extremePrice, $extremePriceTimestamp)  = $this->getRecentRelativeExtremePrice($since, 'max');

        return $withTimestamp ? [$extremePrice, $extremePriceTimestamp] : $extremePrice;
    }

    /**
     * Return the userId from the position.
     *
     * @return ObjectId
     */
    public function getUserId()
    {
        return $this->positionEntity->user->_id;
    }

    /**
     * Update the position entity with a new version.
     *
     * @param BSONDocument $position
     */
    public function updatePositionEntity(BSONDocument $position)
    {
        $this->positionEntity = $position;
    }

    /**
     * Get position entity object
     *
     * @return BSONDocument
     */
    public function getPositionEntity()
    {
        return $this->positionEntity;
    }

    /**
     * Get array with extra info to be added to returned positions to FE
     *
     * @param bool $includePair
     *
     * @return array
     */
    public function getExtraSymbolsAsArray($includePair = false)
    {
        $marketData = $this->getExchangeMediator()
            ->getMarketEncoder()->getMarket($this->getSymbol());
        // TODO: improve this to handle a market data structure with fake
        // or default values so we can go ahead (->getMarketOrDefault method)
        if (null === $marketData) {
            $ret = [
                'unitsInvestment' =>  $this->getQuote(),
                'unitsAmount'     =>  $this->GetBase(),
                'short'           => $this->getSymbolWithSlash(),
                'tradeViewSymbol' => $this->getSymbol()
            ];
        } else {
            $ret = [
                'unitsInvestment' => $marketData->getUnitsInvestment(),
                'unitsAmount'     => $marketData->getUnitsAmount(),
                'short'           => $marketData->getShort(),
                'tradeViewSymbol' => $marketData->getTradeViewSymbol(),
            ];
        }

        if ($includePair) {
            $ret['pair'] = $this->getSymbol();
        }

        return $ret;
    }


    /**
     * Get trailing stop trigger priority
     *
     * @return string
     */
    public function getTrailingStopTriggerPriority()
    {
        return $this->getPriority4('trailingStopTriggerPriority');
    }
    /**
     * Get trailing stop trigger priority
     *
     * @return string
     */
    public function getStopLossPriority()
    {
        return $this->getPriority4('stopLossPriority');
    }
    /**
     * Check stop loss data differs
     *
     * @param string $stopLossPriority   priority
     * @param float  $stopLossPrice      price
     * @param float  $stopLossPercentage percentage
     * 
     * @return boolean
     */
    public function stopLossNotEqualsTo(
        $stopLossPriority,
        $stopLossPrice,
        $stopLossPercentage
    ) {
        return $this->pricePercentagePriorityNotEqualsTo(
            $stopLossPriority,
            $stopLossPrice,
            $stopLossPercentage,
            'stopLossPrice',
            'stopLossPercentage'
        );
    }
    /**
     * Check trailing stop trigger differs
     *
     * @param string $trailingStopTriggerPriority    priority
     * @param float  $trailingStopTriggerPrice       price
     * @param float  $trailingStopTriggerPercentage  percentage
     * @param float  $trailingStopPercentage         stop percentage
     * @param float  $trailingStopDistancePercentage distance
     * 
     * @return boolean
     */
    public function trailingStopNotEqualsTo(
        $trailingStopTriggerPriority,
        $trailingStopTriggerPrice,
        $trailingStopTriggerPercentage,
        $trailingStopPercentage,
        $trailingStopDistancePercentage
    ) {
        return $this->pricePercentagePriorityNotEqualsTo(
            $trailingStopTriggerPriority,
            $trailingStopTriggerPrice,
            $trailingStopTriggerPercentage,
            'trailingStopTriggerPrice',
            'trailingStopTriggerPercentage'
        ) || $trailingStopPercentage != $this->positionEntity->trailingStopPercentage
        ||  $trailingStopDistancePercentage != $this->positionEntity->trailingStopDistancePercentage;
    }
    /**
     * Convert percentage to factor
     *
     * @param float $percentage percentage
     * 
     * @return float|boolean
     */
    public function convertPercentageToFactor($percentage)
    {
        if (empty($percentage)) {
            return false;
        }

        $value = (float)$percentage;
        return 1 + $value / 100;
    }

    /**
     * Check if new take profits are different from current ones
     *
     * @param array $takeProfits take profit targets
     * @param boolean $checkDone check done targets too
     * 
     * @return boolean
     */
    public function takeProfitsNotEqualTo($takeProfits, $checkDone = false)
    {
        return $this->_targetsNotEqualsTo(
            $takeProfits, 
            $this->positionEntity->takeProfitTargets,
            [
                'amountPercentage' => false,
                'priceTargetPercentage' => false,
                'postOnly' => false,
                'pricePriority' => 'percentage'
            ]
        );

    }
    /**
     * Check if new rebuys are different from current ones
     *
     * @param array $reBuys rebuys targets
     * @param boolean $checkDone check done targets too
     * 
     * @return boolean
     */
    public function reBuysNotEqualsTo($reBuys, $checkDone = false)
    {
        return $this->_targetsNotEqualsTo(
            $reBuys, 
            $this->positionEntity->reBuyTargets,
            [
                'quantity' => false,
                'triggerPercentage' => false,
                'postOnly' => false,
                'pricePriority' => 'percentage'
            ]
        );

    }
    /**
     * Check if target is done
     *
     * @param object $target target 
     * 
     * @return boolean
     */
    public function targetIsDone($target)
    {
        return ((isset($target->done) && $target->done)
            || (isset($target->cancel) && $target->cancel)
            || (isset($target->skipped) && $target->skipped));
    }
    /**
     * Check if targets (array-object) are not equal
     *
     * @param array $newTargets    new targets
     * @param object $oldTargets   current targets
     * @param array $propertyArray properties to check
     * @param boolean $checkDone   check done targets too
     * 
     * @return boolean
     */
    private function _targetsNotEqualsTo($newTargets, $oldTargets,  $propertyArray, $checkDone = false)
    {
        if (!$newTargets && !$oldTargets) {
            return false;
        }
    
        if (!$newTargets || !$oldTargets) {
            return true;
        }
    
        if (count($newTargets) != count((array)$oldTargets)) {
            return false;
        }
    
        foreach ($newTargets as $target) {
            $targetId = $target['targetId'];
            if (!$checkDone && isset($oldTargets->$targetId) && $this->targetIsDone($oldTargets->$targetId)) {
                continue;
            }
    
            if (!isset($oldTargets->$targetId)) {
                return true;
            }

            foreach ($propertyArray as $prop => $defValue) {
                if (($oldTargets->$targetId->$prop ?? $defValue) != ($target[$prop] ?? $defValue)) {
                    return true;
                }
            }
    
        }
    
        return false;
    }

    /**
     * Get price and percentage for rebuys target
     *
     * @param float $price buy price
     * 
     * @return float[]
     */
    public static function getRebuysTargetPriceAndPercentage($target, $price = null)
    {        
        return self::_getPriceAndPercentage4(
            $target,
            'pricePriority',
            'triggerPercentage',
            'priceTarget',
            $price
        );
    }
     /**
     * Get price and percentage for take profit target
     *
     * @param float $price buy price
     * 
     * @return float[]
     */
    public static function getTakeProfitTargetPriceAndPercentage($target, $price = null)
    {        
        return self::_getPriceAndPercentage4(
            $target,
            'pricePriority',
            'priceTargetPercentage',
            'priceTarget',
            $price
        );
    }

    
    /**
     * Get price and percentage for stop loss
     *
     * @param float $price buy price
     * 
     * @return float[]
     */
    public function getStopLossPriceAndPercentage($price = null)
    {        
        return $this->getPriceAndPercentage4(
            'stopLossPriority',
            'stopLossPercentage',
            'stopLossPrice',
            $price
        );
    }

    /**
     * Get the stop loss price either from fixed price or percentage.
     * @return float
     */
    public function getStopLossPrice()
    {
        $priority = $this->getStopLossPriority();
        if ('price' === $priority && !empty($this->positionEntity->stopLossPrice)) {
            return (float)$this->positionEntity->stopLossPrice;
        } elseif (!empty($this->positionEntity->stopLossPercentage)) {
            return (float) ($this->getAverageEntryPrice() * $this->positionEntity->stopLossPercentage);
        } else {
            return 0.0;
        }
    }

    /**
     * Get price and percentage for trailing stop trigger
     *
     * @param float $price price
     * 
     * @return float[]
     */
    public function getTrailingStopTriggerPriceAndPercentage($price = null)
    {
        return $this->getPriceAndPercentage4(
            'trailingStopTriggerPriority',
            'trailingStopTriggerPercentage',
            'trailingStopTriggerPrice',
            $price
        );
    }
    /**
     * Calculate the price and percentage for stopLoss ...
     *
     * @param string $priorityField   priority field
     * @param string $percentageField percentage field
     * @param string $priceField      price field
     * @param float  $price           price
     * 
     * @return float[]
     */
    private function getPriceAndPercentage4(
        $priorityField,
        $percentageField,
        $priceField,
        $price = null
    ) {
        if (null === $price) {
            $price = is_object($this->positionEntity->avgBuyingPrice) ? $this->positionEntity->avgBuyingPrice->__toString() : $this->positionEntity->avgBuyingPrice;
        }

        return self::_getPriceAndPercentage4(
            $this->positionEntity,
            $priorityField,
            $percentageField,
            $priceField,
            $price
        );
    }

    /**
     * Calculate the price and percentage for
     *
     * @param object $object          container object
     * @param string $priorityField   priority field
     * @param string $percentageField percentage field
     * @param string $priceField      price field
     * @param float  $price           price
     * 
     * @return float[]
     */
    private static function _getPriceAndPercentage4(
        $object,
        $priorityField,
        $percentageField,
        $priceField,
        $price
    ) {
        if (!empty($object->$priorityField)
            && 'price' === $object->$priorityField 
            && !empty($object->$priceField)
        ) {
            $percentage = ($object->$priceField / $price) * 100 - 100;
            $price = $object->$priceField;
        } else {
            $percentage = !empty($object->$percentageField)
                ? ($object->$percentageField * 100) - 100 : false;
            $price = !is_numeric($object->$percentageField)
                ? false : $price * $object->$percentageField;
        }
        return [$price, $percentage];
    }

    /**
     * Get priority for fieldname
     *
     * @param string $fieldName priority fieldname
     * 
     * @return string
     */
    private function getPriority4($fieldName)
    {
        $priority = $this->positionEntity->$fieldName ?? 'percentage';
        return (false === $priority) ? 'percentage' : $priority;
    }

    /**
     * Check price/percentage priority
     *
     * @param string $priority        prioroty
     * @param float  $price            price
     * @param float  $percentage       percentage
     * @param string $priceField      price field in position
     * @param string $percentageField percentage field in position
     * 
     * @return boolean
     */
    private function pricePercentagePriorityNotEqualsTo(
        $priority,
        $price,
        $percentage,
        $priceField,
        $percentageField
    ) {
        return ($priority != $this->getStopLossPriority()
            || ('price' == $priority && $price != $this->positionEntity->$priceField)
            || ('price' != $priority && $percentage != $this->positionEntity->$percentageField)
        );
    }

    /**
     * Return the real first date and last date from the trades.
     * @param \MongoDB\Model\BSONDocument $position
     * @return array
     */
    public function getEntryAndExitDate()
    {
        $entryDate = false;
        $exitDate = false;

        if (empty($this->positionEntity->trades)) {
            return [$entryDate, $exitDate];
        }

        $side = !empty($this->positionEntity->side) ? strtolower($this->positionEntity->side) : 'long';
        $isBuyEntry = 'long' === $side;
        foreach ($this->positionEntity->trades as $trade) {
            if ($trade->time < 10000000000) {
                $trade->time = $trade->time * 1000;
            }

            if (($isBuyEntry && $trade->isBuyer) || (!$isBuyEntry && !$trade->isBuyer)) {
                if (!$entryDate || $trade->time < $entryDate) {
                    $entryDate = $trade->time;
                }
            } else {
                if (!$exitDate || $trade->time > $exitDate) {
                    $exitDate = $trade->time;
                }
            }
        }

        if ($entryDate === $exitDate) {
            $exitDate++;
        }

        return [$entryDate, $exitDate];
    }

}
