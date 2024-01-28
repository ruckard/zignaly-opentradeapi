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
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Process\DIContainer;
use Zignaly\service\ZignalyLastPriceService;
use Zignaly\utils\ConsoleMonolog;

class  ZignalyLastPriceRedisService implements ZignalyLastPriceService {
    /** @var Monolog */
    private $monolog;
    /** @var RedisHandler */
    private $redisHandler;
    /** @var /HistoryDB2 */
    private $HistoryDB;

    public function __construct(RedisHandler $redisHandler = null, $monolog = null){
        if ($monolog == null) {
            $container = DIContainer::getContainer();
            if ($container->has('monolog')) {
                $this->monolog = $container->get('monolog');
            } else {
                $container->set('monolog', new Monolog('ZignalyLastPriceRedisService'));
                $this->monolog = $container->get('monolog');
            }
        } else {
            $this->monolog = $monolog;
        }

        $this->redisHandler = $redisHandler;
        if ($this->redisHandler == null){
            $this->redisHandler = new RedisHandler($monolog, 'ZignalyLastPrices');
        }
        $container = DIContainer::getContainer();
        $this->HistoryDB = $container->get('allHistoryPrices.storage.read');

    }
    /**
     * get last trade for exchange/symbol
     * 
     * @param string $exchange
     * @param string $symbol
     * @return float|bool
     */
    public function lastPriceForSymbol($exchange, $symbol) {
        $currentPrice = $this->lastPriceStrForSymbol($exchange, $symbol);
        if (!$currentPrice){
            $currentPrice = floatval($currentPrice);
        }
        return $currentPrice;
    }
    /**
     * get last trade for exchange/symbol
     *
     * @param string $exchange
     * @param string $symbol
     * @return string|bool
     */
    public function lastPriceStrForSymbol($exchange, $symbol) {
        $realExchangeName = ZignalyExchangeCodes::getRealExchangeName($exchange);
        /* @luis this conversion would not be needed
        if ($realExchangeName == 'Binance' && strpos($symbol, 'YOYOW') !== false) {
            $symbol = str_replace('YOYOW', 'YOYO', $symbol);
        }*/
        $key = $realExchangeName . 'LastPrice_' . $symbol;
        $currentPrice = $this->redisHandler->getKey($key, false);
        if ($currentPrice == null || !$currentPrice || !$currentPrice > 0) {
            $currentPrice = $this->HistoryDB->getLastPrice($realExchangeName, $symbol);
        }

        if (!$currentPrice) {
            $this->monolog->sendEntry('debug', "Last price not found for symbol $symbol on exchange $exchange/$realExchangeName");
        }

        return $currentPrice;
    }

    /**
     * Undocumented function
     * 
     * @param string $exchange
     * @param string $symbol
     * @return array
     */
    public function getExchangeVolume4Symbol($exchange, $symbol){
        $key = 'volume_' . $exchange . ':' . $symbol;
        return $this->redisHandler->getHashAll($key);
    }
}