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

use Monolog;
use RedisHandler;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\service\entity\ZignalyMarketData;
use Zignaly\service\ZignalyMarketDataService;

/**
 * Implements Zignaly Market Data using Redis storage.
 *
 * @package Zignaly\redis
 */
class ZignalyMarketDataRedisService implements ZignalyMarketDataService
{
    /** @var Monolog */
    private $monolog;
    /** @var RedisHandler */
    private $redisHandler;

    public function __construct(RedisHandler $redisHandler, $Monolog = null)
    {
        $this->initiateMonolog($Monolog);
        $this->redisHandler = $redisHandler;
        if ($this->redisHandler == null) {
            $this->redisHandler = new RedisHandler($Monolog, 'ZignalyData');
        }
    }


    /**
     * @param Monolog|null $Monolog
     */
    private function initiateMonolog($Monolog)
    {
        if ($Monolog == null){
            $this->monolog = new Monolog('ZignalyMarketDataRedisService');
        } else {
            $this->monolog = $Monolog;
        }
    }

    /**
     * Map alias exchange (Brokers) to real exchange name ID.
     *
     * @param string $exchange An exchange name ID.
     *
     * @return string
     */
    private function getExchangeKey(string $exchange): string
    {
        $realExchangeName = ZignalyExchangeCodes::getRealExchangeName($exchange);
        // Fallback to exchange name when real name mapping don't retrieve a result.
        if (empty($realExchangeName)) {
            $realExchangeName = $exchange;
        }

        return 'exchange:'.strtoupper($realExchangeName);
    }

    /**
     * @inheritDoc
     */
    public function getMarkets($exchange)
    {
        $realExchangeName = ZignalyExchangeCodes::getRealExchangeName($exchange);
        $keyName = $this->getExchangeKey($exchange);
        $exchangeData = $this->redisHandler->getHashAll($keyName);
        $markets = [];

        // Fallback to exchange name when real name mapping don't retrieve a result.
        if (empty($realExchangeName)) {
            $exchangeClass = strtolower($exchange);
        } else {
            $exchangeClass = strtolower($realExchangeName);
        }

        $marketEncoder = BaseMarketEncoder::newInstance($exchangeClass);
        foreach ($exchangeData as $datum) {
            $market = json_decode($datum, true);
            if (isset($market['quoteId']) && isset($market['baseId'])) {
                $market['coinrayQuote'] = $marketEncoder->coinrayQuote($market);
                $market['coinrayBase'] = $marketEncoder->coinrayBase($market);
                $markets[] = new ZignalyMarketData($exchange, $market);
            }
        }

        return $markets;
    }

    /**
     * @inheritDoc
     */
    public function getMarket($exchange, $symbol)
    {
        $keyName = $this->getExchangeKey($exchange);
        $data = $this->redisHandler->getHash($keyName, $symbol);
        if (!$data) {
            //$this->monolog->sendEntry('DEBUG', "Data for symbol $symbol in exchange $keyName not found.");
            return false;
        }

        $market = json_decode($data, true);

        return new ZignalyMarketData($exchange, $market);
    }

    /**
     * @inheritDoc
     */
    public function isExchangeSupported($exchange)
    {
        $keyName = $this->getExchangeKey($exchange);

        return $this->redisHandler->getHash($keyName, 'name');
    }

    /**
     * @inheritDoc
     */
    public function addMarket($exchange, $symbol, $metadata)
    {
        $keyName = $this->getExchangeKey($exchange);
        $this->redisHandler->setHash($keyName, $symbol, json_encode($metadata->asArray()));
    }

    /**
     * @inheritDoc
     */
    public function removeMarket($exchange, $symbol)
    {
        $keyName = $this->getExchangeKey($exchange);
        $this->redisHandler->removeHashMember($keyName, $symbol);
    }

    /**
     * @inheritDoc
     */
    public function addSupportedExchange($exchange, $name)
    {
        $keyName = $this->getExchangeKey($exchange);
        $this->redisHandler->setHash($keyName, 'name', $name);
    }

    /**
     * @inheritDoc
     */
    public function getExchanges()
    {
        $exchangesKeys = $this->redisHandler->keys('exchange:*');

        return array_map(function($exchangeKey) {
           return str_replace('exchange:', '', $exchangeKey);
        }, $exchangesKeys);
    }

}