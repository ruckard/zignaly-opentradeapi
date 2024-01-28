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

class ZignalyLastMarkPricesRedisService extends ZignalyLastTradesRedisService {
    /**
     * constructor
     *
     * @param RedisHandler $redisHandler
     */
    public function __construct(RedisHandler $redisHandler = null, $monolog = null) {
        parent::__construct($redisHandler, $monolog);
    }
    public function genRedisKeyPrefix($exchange){
        // When exchange don't exists we cannot determine a key but allow execution to continue so it stop later
        // when data is not retrieved for the key.
        if (!ZignalyExchangeCodes::isValidZignalyExchange($exchange)){
            return '';
        }

        $realExchangeName = ZignalyExchangeCodes::getRealExchangeName($exchange);
        return $realExchangeName . "_HistMarkPrice_";
    }
}