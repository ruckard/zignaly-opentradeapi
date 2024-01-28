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


pcntl_async_signals(true);
pcntl_signal(SIGTERM, "sigHandler");

use Zignaly\Process\DIContainer;

require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../vendor/autoload.php';
require_once dirname(__FILE__) . '/../class/Monolog.php';
require_once dirname(__FILE__) . '/../class/RedisHandler.php';
require_once dirname(__FILE__) . '/tradesFromExchangeConfiguration.php';
$container = DIContainer::init();

$exchangeCode = $argv[1] ?? null;
if (null === $exchangeCode) {
    exit("Exchange is mandatory. Please, specify one.\n");
}

$bulkSize = $argv[2] ?? 3000;

$debug = isset($argv[3]) || getenv('LANDO') === 'ON';

$exchange = getExchangeFromConfiguration($exchangeCode);

if (empty($exchange)) {
    exit("Unrecognized exchange: $exchangeCode.\n");
}

$continueLoop = true;
$Monolog = new Monolog($exchange['log']);
$RedisHandlerZignalyLastPrices = new RedisHandler($Monolog, 'ZignalyLastPrices');
$RedisHandlerZignalyPriceUpdate = new RedisHandler($Monolog, 'ZignalyPriceUpdate');

$scriptStartTime = time();

while ($continueLoop) {
    try {
        $Monolog->trackSequence();
        $popMembers = $RedisHandlerZignalyLastPrices->popManyFromSet($exchange['queue'], $bulkSize);
        if (empty($popMembers)) {
            // if no messages retrieved, wait 100 ms before next request
            usleep(100000);
            continue;
        }
        $priceUpdates = [];
        $trades = [];
        foreach ($popMembers as $popMember => $time) {
            if ($popMember == null) {
                continue;
            }
            $trade = json_decode($popMember, true);
            if ($debug) {
                $Monolog->sendEntry('debug', "pop: ", $trade);
            }

            $trades[] = [
                'symbol' => $trade['symbol'],
                'price' => $trade['price'],
                'datetime' => $trade['datetime'],
            ];
        }
        if ($trades) {
            storeSqlTradesFromExchange($trades, $exchange['store']);
        }
    } catch (Exception $e) {
        if (!isset($trade)) {
            $trade = [];
        }
        if (strpos($e->getMessage(), 'duplicate key error collection')  === false) {
            $Monolog->sendEntry('critical', "Processing trade failed: " . $e->getMessage(), $trade);
            if (strpos($e->getMessage(), "Authentication required")) {
                $Monolog->sendEntry('critical', "Terminating process");
                sleep(5);
                exit();
            }
        }
    }
}

function storeSqlTradesFromExchange(array $trades, string $store)
{
    $lines = [];
    foreach ($trades as $trade) {
        if (!isset($lines[$trade['symbol']])) {
            $lines[$trade['symbol']] = '';
        }
        $lines[$trade['symbol']] .= $trade['datetime'].','.$trade['price'].\PHP_EOL;
    }
    foreach ($lines as $symbol => $content) {
        $file = getTradesSqlFileName($store, $symbol, getTenMinutesSuffix());
        file_put_contents($file, $content, \FILE_APPEND);
    }
}

/**
 * Avoid the supervisor daemon to kill the process in the middle of the execution.
 * @param int $signo
 */
function sigHandler(int $signo)
{
    global $continueLoop;

    if (SIGTERM === $signo) {
        $continueLoop = false;
    }
}
