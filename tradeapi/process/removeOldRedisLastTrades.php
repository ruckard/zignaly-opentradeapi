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


use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\redis\ZignalyLastTradesRedisService;
use Zignaly\utils\ConsoleMonolog;

require_once dirname(__FILE__) . '/../loader.php';
global $continueLoop;
$process = 'removeOldRedisLastTrades';
$Monolog = new Monolog($process);

$exchanges = [
    ZignalyExchangeCodes::ZignalyBinance,
    ZignalyExchangeCodes::ZignalyKucoin,
    ZignalyExchangeCodes::ZignalyBinanceFutures,
    ZignalyExchangeCodes::ZignalyBitmex,
    ZignalyExchangeCodes::ZignalyVcce,
    ZignalyExchangeCodes::ZignalyAscendex,
];
$scriptStartTime = time();
$diff = 60 * 60 * 1000;
$redisLastTrades = new RedisHandler($Monolog, 'Last3DaysPrices');
$lastTradesProvider = new ZignalyLastTradesRedisService($redisLastTrades);

while ($continueLoop){
    try {
        $Monolog->trackSequence();

        $milliseconds = round(microtime(true) * 1000);
        $milliseconds = $milliseconds - $diff;
        
        foreach($exchanges as $exchange){
            $keyName = $lastTradesProvider->genRedisKeyPrefix($exchange);
            $exchangeData = $redisLastTrades->keys($keyName."*");
            foreach ($exchangeData as $datum) {
                $currentSymbol = str_replace($keyName,"", $datum);
                //$Monolog->sendEntry('debug', "Deleting {$exchange}-{$currentSymbol} up to ". gmdate("[d/m/Y H:i:s]", $milliseconds/1000));
                $lastTradesProvider->deleteBeforeTimestampAndSymbol($milliseconds, $exchange, $currentSymbol);
            }
        }

    } catch (Exception $e){
        $Monolog->sendEntry("critical", "Processing deleting last trades redis db failed: " . $e->getMessage());
    }

    $Monolog->sendEntry("debug", "sleep some seconds");
    sleep(rand(0, 3600));
}