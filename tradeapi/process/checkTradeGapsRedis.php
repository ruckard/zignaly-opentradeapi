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


use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangeFactory;
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Process\DIContainer;
use Zignaly\utils\ArrayUtils;

require_once __DIR__ . '/../loader.php';
global $continueLoop;

$processName = 'checkTradeGapsRedis';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
$lastTradesProvider = $container->get('recentHistoryPrices');
$scriptStartTime = time();
$exchanges = [
    //ZignalyExchangeCodes::ZignalyBinance,
    //ZignalyExchangeCodes::ZignalyKucoin,
    ZignalyExchangeCodes::ZignalyBinanceFutures
];

/** @var BaseExchange[] */
$exchangeInstances = [];
$exchangeLastSearch = [];
$exchangeLastExec = [];

foreach($exchanges as $exchange) {
    $exchangeLastSearch[$exchange] = array();
    $exchangeLastExec[$exchange] = array();
}

$backWards = 2 /*hours*/ * 60 /*minutes*/ * 60 /*seconds*/ * 1000 /*milliseconds*/;
$sleepSeconds = 60 * 10; // 10 minutes
while ($continueLoop) {
    $Monolog->trackSequence();
    $startTime = time();

    foreach($exchanges as $exchange) {
        if (!isset($exchangeInstances[$exchange])){
            $options = array(
                'timeout' => 30000,
                'enableRateLimit' => true,
                'adjustForTimeDifference' => true,
                'verbose'=>false
            );
            if (isset($ccxtExchangesGlobalConfig['common'])){
                $options = array_merge($options, $ccxtExchangesGlobalConfig['common']);
            }
            $exchangeConfigId = strtolower($exchange);
            if (isset($ccxtExchangesGlobalConfig['exchanges']) && isset($ccxtExchangesGlobalConfig['exchanges'][$exchangeConfigId])){
                $options = array_merge($options, $ccxtExchangesGlobalConfig['exchanges'][$exchangeConfigId]);
            }
            $exchangeInstances[$exchange] = ExchangeFactory::newInstance($exchangeConfigId, new ExchangeOptions($options));
        }
        $markets = $exchangeInstances[$exchange]->loadMarkets(true);
        // TODO: add fetchTrades to BaseExchange
        $reflection = new ReflectionClass($exchangeInstances[$exchange]);
        $property = $reflection->getProperty("exchange");
        $property->setAccessible(true);
        $exch = $property->getValue($exchangeInstances[$exchange]);
        //
        $marketTotalCount = count($markets);
        $marketCount = 0;
        foreach(array_keys($markets) as $marketSymbol){
            $marketCount++;
            //$marketSymbol = "QTUM/ETH";
            $internalSymbol = str_replace('/','',$marketSymbol);
            // check if restart worker
            // get current time in ms and calculate from-to values to search
            $milliseconds = round(microtime(true) * 1000);
            $upto = $milliseconds - 2 * 60 * 1000; // 2 minutes
            if (isset($exchangeLastSearch[$exchange][$marketSymbol])){
                $since = $exchangeLastSearch[$exchange][$marketSymbol];
                
            } else {
                $since = $milliseconds - $backWards;
            }
            // if not first execution for exchange/symbol print how much time spent in get back here
            if (isset($exchangeLastExec[$exchange][$marketSymbol])){
                $Monolog->sendEntry('debug', "({$marketCount}/{$marketTotalCount}) {$exchange}/{$marketSymbol}  last execution at ". gmdate("[d/m/Y H:i:s]", $exchangeLastExec[$exchange][$marketSymbol]/1000). " new execution at ".  gmdate("[d/m/Y H:i:s]", $milliseconds/1000));
            } else {
                $Monolog->sendEntry('debug',"({$marketCount}/{$marketTotalCount}) Starting symbol {$exchange}/{$marketSymbol} at ".gmdate("[d/m/Y H:i:s]", $milliseconds/1000));
            }
            $exchangeLastExec[$exchange][$marketSymbol] = $milliseconds;
            // prepare gaps vars
            $gaps = [];
            $currentGap = null;
            $currentGapCount = 0;
            $totalTradesLost = 0;
            $lastTrade = null;
            $fromId = null;
            $lastTrade = null;
            while ($continueLoop) {
                // check if restart worker
                // get trades from exchange from timestamp
                $trades = $exch->fetch_trades($marketSymbol, $since);
                $Monolog->sendEntry('debug','Trades fetched  for symbol '.$marketSymbol . ' since '. gmdate("[d/m/Y H:i:s]", $since/1000) .' ' . count($trades));

                // get trades up to calculated timestamp
                $trades2process = array();
                foreach ($trades as $trade) {
                    if ($trade['timestamp'] >= $upto) {
                        $continueLoop = false;
                        break;
                    }
                    $trades2process[] = $trade;
                }

                $count = count($trades2process);   
                $Monolog->sendEntry('debug','Trades filtered for symbol '.$marketSymbol . ' ' . $count);
                // no trades exit
                if ($count == 0) break;
                if ($lastTrade != null){
                    unset($lastTrade['inquery']);
                    if (ArrayUtils::recursiveCheck($lastTrade, $trades2process[$count-1])){
                        break;
                    }
                }

                // get last trade timestamp to next fetch trades call
                $since = $trades2process[$count-1]['timestamp'];
                //$fromId = $trades2process[$count-1]['id'];
                $lastTrade = $trades2process[$count-1];
                while (count($trades2process) > 0){
                    // get array piece max 500 trades
                    $tradesPiece = array_splice($trades2process, 0, 500);
                    $countTradesPiece = count($tradesPiece);
                    // get min and max timestamp to serach in database
                    $minTradeTimestamp = array_reduce($tradesPiece,function($a,$b){
                        return $a['timestamp'] < $b['timestamp'] ? $a : $b;
                    }, $tradesPiece[0])['timestamp'];
                    $maxTradeTimestamp = array_reduce($tradesPiece,function($a,$b){
                        return $a['timestamp'] > $b['timestamp'] ? $a : $b;
                    }, $tradesPiece[0])['timestamp'];
                    // get trades from database
                    $dbTrades = $lastTradesProvider->findTradeBetween($exchange,$internalSymbol,$minTradeTimestamp,$maxTradeTimestamp, $countTradesPiece*2);
                    // mark trades in query
                    $c = 0;
                    foreach($dbTrades as $dbTrade){
                        $c++;
                        for($i=0;$i<$countTradesPiece;$i++){
                            if (($tradesPiece[$i]['timestamp'] == $dbTrade->timestamp) && ($tradesPiece[$i]['price'] == $dbTrade->price)){
                                $tradesPiece[$i]['inquery'] = true;
                            } else if ($tradesPiece[$i]['timestamp'] > $dbTrade->timestamp) {
                                break;
                            }
                        }
                    }

                    // find gaps
                    foreach($tradesPiece as $trade){
                        $lastTrade = $trade;
                        if (isset($trade['inquery']) && $trade['inquery']){
                            if ($currentGap != null){
                                // found, so finish gap
                                $gaps[] = $currentGap . "-" .gmdate("[d/m/Y H:i:s]", $trade['timestamp']/1000) . " " . $trade['timestamp'] . " -> trades:".$currentGapCount;
                                $totalTradesLost = $totalTradesLost + $currentGapCount;
                                $currentGap = null;
                                $currentGapCount = 0;
                            } else {
                                // nothing to do
                            }
                        } else {
                            if ($currentGap != null){
                                // still missing
                                $currentGapCount++;
                            } else {
                                // first missing
                                $currentGap = gmdate("--> [d/m/Y H:i:s]", $trade['timestamp']/1000) . " " . $trade['timestamp']; 
                                $currentGapCount = 1;
                            }
                        }
                    }
                }
            }

            if ($currentGap != null){
                // get last trade to finish gap
                $gaps[] = $currentGap . "-" .gmdate("[d/m/Y H:i:s]", $lastTrade['timestamp']/1000) . " " . $lastTrade['timestamp'] . " -> trades:".$currentGapCount;
                $totalTradesLost = $totalTradesLost + $currentGapCount;
            }

            if (count($gaps)>0){
                $Monolog->sendEntry('critical', $exchange . " " . $marketSymbol . "\n" . implode("\n", $gaps));
            } else {
                $Monolog->sendEntry('debug','No gaps for symbol '.$marketSymbol);
            }

            $exchangeLastSearch[$exchange][$marketSymbol] = $since;
        }
    }

    $Monolog->sendEntry("debug", "sleep {$sleepSeconds} seconds");
    sleep($sleepSeconds);
}