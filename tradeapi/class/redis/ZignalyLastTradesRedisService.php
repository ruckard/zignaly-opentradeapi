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

namespace Zignaly\redis;

use RedisHandler;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\service\ZignalyLastTradesService;
use Zignaly\utils\SimpleArrayAccessor;
use Zignaly\utils\ConsoleMonolog;

class ZignalyLastTradesRedisService implements ZignalyLastTradesService {
    /** @var Monolog */
    private $monolog;
    /** @var RedisHandler */
    protected $redisHandler;
    /**
     * constructor
     *
     * @param RedisHandler $redisHandler
     */
    public function __construct(RedisHandler $redisHandler = null, $monolog = null) {
        $this->monolog = $monolog;
        if ($this->monolog == null){
            $classParts = explode('\\', get_class());
            $this->monolog = new ConsoleMonolog(end($classParts));
        }
        $this->redisHandler = $redisHandler;
        if ($this->redisHandler == null){
            $this->redisHandler = new RedisHandler($monolog, 'Last3DaysPrices');
        }
    }
    public function genRedisKeyPrefix($exchange){
        // When exchange don't exists we cannot determine a key but allow execution to continue so it stop later
        // when data is not retrieved for the key.
        if (!ZignalyExchangeCodes::isValidZignalyExchange($exchange)){
            return '';
        }

        $realExchangeName = ZignalyExchangeCodes::getRealExchangeName($exchange);
        return $realExchangeName . "_LastDay_";
    }
    protected function _genRedisKey($exchange, $symbol){
        return $this->genRedisKeyPrefix($exchange) . $symbol;
    }
    /**
     * insert if not exists
     *
     * @param string $exchange
     * @param string $symbol
     * @param int $timestamp
     * @param float $price
     * @return bool
     */
    public function insertNotDuplicatedHash($exchange, $symbol, $timestamp, $price) {
        return $this->redisHandler->addSortedSet($this->_genRedisKey($exchange, $symbol), $timestamp, $timestamp."_".$price);
    }
    /**
     * insert
     *
     * @param string $exchange
     * @param string $symbol
     * @param int $timestamp
     * @param float $price
     * @return void
     */
    public function insert($exchange, $symbol, $timestamp, $price) {
        return $this->insertNotDuplicatedHash($exchange, $symbol, $timestamp, $price);
    }
    /**
     * delete all before timestamp
     *
     * @param string $timestamp
     * @return void
     */
    public function deleteBeforeTimestamp($timestamp, $exchange) {
        $prefix = $this->genRedisKeyPrefix($exchange);
        $allKeys = $this->redisHandler->keys($prefix."*");
        $ret = true;
        foreach ($allKeys as $key){
            $ret = $ret && $this->redisHandler->removeSortedSet($key, null, $timestamp);
        }
        return $ret;
    }
    protected function _unpack($v) {
        $parts = explode("_",$v, 2);
        if (count($parts) > 1 ) return [
            "ts" => $parts[0],
            "pr" => $parts[1]
        ];
        throw new \UnexpectedValueException ($v);
        /*
        return [
            "ts" => $parts[0],
            "pr" =>  null
        ];
        */
    }
    /**
     * get last price
     *
     * @param string $exchange
     * @param string $symbol
     * @return float
     */
    public function getLastPrice($exchange, $symbol) {
        $values = $this->redisHandler->zRevRangeByScore($this->_genRedisKey($exchange, $symbol), '+inf', '-inf', [0,1]);
        if (count($values) == 0) return null;
        return $this->_unpack($values[0])['pr'];
    }
    /**
     * get lower price
     * get all prices from highest score to lowest up to 50000
     *
     * @param string $exchange
     * @param string $symbol
     * @param int $since
     * @param bool $includeTimestamp
     * @return float|array
     */
    public function getLowerPrice($exchange, $symbol, $since, $includeTimestamp = false)
    {
        return $this->getLowerOrHigherPrice($exchange, $symbol, $since, 'lower', $includeTimestamp);
    }

    /**
     * get higher price
     * get all prices from highest score to lowest up to 50000
     *
     * @param string $exchange
     * @param string $symbol
     * @param int $since
     * @param bool $includeTimestamp
     * @return float|array
     */
    public function getHigherPrice($exchange, $symbol, $since, $includeTimestamp = false)
    {
        return $this->getLowerOrHigherPrice($exchange, $symbol, $since, 'higher', $includeTimestamp);
    }

    /**
     * Return higher or lower price since the given date.
     * @param $exchange
     * @param $symbol
     * @param $since
     * @param $type
     * @param $includeTimestamp
     * @return array|mixed|null
     */
    private function getLowerOrHigherPrice($exchange, $symbol, $since, $type, $includeTimestamp)
    {
        $counter = 0;
        do {
            $values = $this->redisHandler->zRevRangeByScore($this->_genRedisKey($exchange, $symbol), '+inf', $since, [$counter, 50000]);
            if (is_array($values) && count($values) > 0) {
                if (!isset($price)) {
                    $price = $this->_unpack($values[0])['pr'];
                    $timestamp = $this->_unpack($values[0])['ts'];
                }
                foreach ($values as $value) {
                    $unpacked = $this->_unpack($value);
                    $curPrice = $unpacked['pr'];
                    $curTimestamp = $unpacked['ts'];
                    if (('higher' === $type && $curPrice > $price) || ('lower' === $type && $curPrice < $price)) {
                        $price = $curPrice;
                        $timestamp = $curTimestamp;
                    }
                }
            }
            $counter += 50000; //Rolling back the pagination because is slower.
        } while (is_array($values) && count($values) > 0 && $counter < 50000);

        if (!isset($price)) {
            return null;
        }

        return $includeTimestamp ? [$price, $timestamp] : $price;
    }

    /**
     * get first trade price after timestamp
     * search into last 50000 trades
     *
     * @param string $exchange
     * @param string $symbol
     * @param float $price
     * @param int $timestamp
     * @param bool $isBuy
     *
     * @return \Zignaly\utils\SimpleArrayAccessor
     */

    public function getFirstTradePriceAfterTimestamp($exchange, $symbol, $price, $timestamp, $isBuy = true)
    {
        $valuesDict = $this->redisHandler->zRangeByScore($this->_genRedisKey($exchange, $symbol), $timestamp, '+inf', [0,50000], true);
        if (empty($valuesDict)) {
            return null;
        }
        $ret = array(
            'exchange' => $exchange,
            'symbol' => $symbol,
            'timestamp' => $timestamp,
            'price' => $price,
            'hash' => null,
            'volume' => null
        );

        if ($isBuy) {
            foreach ($valuesDict as $pri => $ts) {
                $unpackedPrice = $this->_unpack($pri)['pr'];
                if ($unpackedPrice <= $price) {
                    $ret['price'] = $unpackedPrice;
                    $ret['timestamp'] = $ts;
                    return new SimpleArrayAccessor($ret);
                }
            }
        } else {
            foreach ($valuesDict as $pri => $ts) {
                $unpackedPrice = $this->_unpack($pri)['pr'];
                if ($unpackedPrice >= $price) {
                    $ret['price'] = $unpackedPrice;
                    $ret['timestamp'] = $ts;
                    return new SimpleArrayAccessor($ret);
                }
            }
        }
        return null;
    }

    /**
     * find trade before or after
     *
     * @param string $exchange
     * @param string $symbol
     * @param int $timestamp
     * @param boolean $before
     * @return void
     */
    public function findTradeBeforeOrAfter($exchange, $symbol, $timestamp, $before=false) {
        if ($before){
            $values = $this->redisHandler->zRevRangeByScore($this->_genRedisKey($exchange, $symbol), $timestamp, '-inf', [0,1]);
        } else {
            $values = $this->redisHandler->zRangeByScore($this->_genRedisKey($exchange, $symbol), $timestamp, '+inf', [0,1]);
        }
        if (count($values) == 0) return null;
        return $this->_unpack($values[0])['pr'];
    }
    /**
     * find trades
     *
     * @param string $exchange
     * @param string $symbol
     * @param int $ts1
     * @param int $ts2
     *
     * @return \Zignaly\utils\SimpleArrayAccessor[]
     */
    public function findTradeBetween($exchange, $symbol, $ts1, $ts2, $limit = 100) {
        $valuesDict = $this->redisHandler->zRangeByScore($this->_genRedisKey($exchange, $symbol), $ts1, $ts2, [0,$limit], true);
        $ret = [];

        foreach ($valuesDict as $price => $timestamp) {
            $unpackedPrice = $this->_unpack($price)['pr'];
            $ret[] = new SimpleArrayAccessor([
                'exchange' => $exchange,
                'symbol' => $symbol,
                'timestamp' => $timestamp,
                'price' => $unpackedPrice,
                'hash' => null,
                'volume' => null
            ]);
        }

        return $ret;
    }
       
    
    /**
     * find price at
     *
     * @param string $exchange
     * @param string $symbol
     * @param int $since
     * @param boolean $toString
     * @return float
     */
    public function findPriceAt($exchange, $symbol, $since, $toString = false) {
        $values = $this->redisHandler->zRangeByScore($this->_genRedisKey($exchange, $symbol), $since, '+inf', [0,1], true);
        if (count($values) == 0) return null;
        return $this->_unpack($values[0])['pr'];
    }
    
    /**
     * return min timestamp
     *
     * @param string $exchange
     * @return int
     */
    public function getMinTimestamp($exchange) {
        $prefix = $this->genRedisKeyPrefix($exchange);
        $allKeys = $this->redisHandler->keys($prefix."*");
        $ret = null;
        foreach ($allKeys as $key){
            $values = $this->redisHandler->zRangeByScore($key, '-inf', '+inf', [0,1], true);
            if (count($values) == 0) continue;
            $value = reset($values);
            $ret = ($ret === null) ? $value : (($value < $ret) ? $value : $ret);
        }
        return $ret;
    }
    
    /**
     * min timestamp 4 symbol
     *
     * @param string $exchange
     * @param string $symbol
     * @return void
     */
    public function getMinTimestampForSymbol($exchange, $symbol) {
        $values = $this->redisHandler->zRangeByScore($this->_genRedisKey($exchange, $symbol), '-inf', '+inf', [0,1], true);
        if (count($values) == 0) return null;
        return reset($values);
    }
    
    /**
     * delete before timestamp and symbol
     *
     * @param int $timestamp
     * @param string $exchange
     * @param string $symbol
     * @return void
     */
    public function deleteBeforeTimestampAndSymbol($timestamp, $exchange, $symbol) {
        return $this->redisHandler->removeSortedSet($this->_genRedisKey($exchange, $symbol), null, $timestamp);
    }

    /**
     * number of trades between timestamps
     *
     * @param string $exchange
     * @param string $symbol
     * @param int $ts1
     * @param int $ts2
     * @return int
     */
    public function countSymbolTrades($exchange, $symbol, $ts1 = null, $ts2 = null){
        if ($ts1 === null) $ts1 = '-inf';
        if ($ts2 === null) $ts2 = '+inf';
        return $this->redisHandler->zCount($this->_genRedisKey($exchange, $symbol), $ts1, $ts2);
    }
    
}