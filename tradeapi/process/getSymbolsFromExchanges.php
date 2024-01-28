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


use Zignaly\exchange\ExchangeFactory;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\Process\DIContainer;
use Zignaly\service\entity\ZignalyMarketData;
use Zignaly\service\ZignalyMarketDataService;

$secondaryDBNode = true;
$excludeRabbit = true;
$retryServerSelection = true;
$socketTimeOut = true;
require_once dirname(__FILE__) . '/../loader.php';
global $continueLoop, $RestartWorker;

$processName = 'getSymbolsFromExchanges';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
/** @var ZignalyMarketDataService */
$marketDataService = $container->get('marketData');
$scriptStartTime = time();

// TODO: Temporal fix until we get them dynamically from a reliable source.
$exchangesNames = [
    'Binance' => 'spot',
    'BinanceFutures' => 'futures',
    'KuCoin' => 'spot',
    'BitMEX' => 'futures',
    //'VCCE' => 'spot',
    'AscendEX' => 'spot'
];

while ($continueLoop) {
    $Monolog->trackSequence();
    foreach ($exchangesNames as $exchangeName => $exchangeType) {
        $RestartWorker->countCycle();
        $RestartWorker->checkProcessStatus($processName, $scriptStartTime, $Monolog, 100);
        $exchange = null;
        try {
            $exchange = ExchangeFactory::createFromNameAndType($exchangeName, $exchangeType, []);
            $marketDataService->addSupportedExchange($exchangeName, $exchangeName);
            $markets = $exchange->loadMarkets(true);
            $marketEncoder = BaseMarketEncoder::newInstance($exchangeName, $exchangeType);

            // remove markets not valid from redis
            /* comment out for now
            try {
                $redisMarkets = $marketDataService->getMarkets($exchangeName);
                echo("Removing old symbols {$exchangeName}\n");
                foreach ($redisMarkets as $redisMarket) {
                    $symbol = $redisMarket->getSymbol();
                    try {
                        $market4symbol = $exchange->market($symbol);
                    } catch (ccxt\BadSymbol $ex) {
                        echo ("Remove symbol {$symbol} {$redisMarket->getId()}\n");
                        $redisKey = $marketEncoder->withoutSlash($symbol);
                        $marketDataService->removeMarket($exchangeName, $redisKey);
                    }
                }
            } catch (Exception $ex) {
                $Monolog->sendEntry('critical', sprintf("Remove symbols failed: %s", $exchangeName, $e->getMessage()));
            }
            */
            foreach ($markets as $market) {
                if ($marketEncoder->validMarket4Zignaly($market)) {
                    $data = $marketEncoder->createMarketFromCcxtMarket($market);
                    $market = $marketEncoder->fromCcxt($market['symbol'], $market);
                    $marketDataService->addMarket($exchangeName, $market, new ZignalyMarketData($exchangeName, $data));
                } else {
                    $Monolog->sendEntry('debug', "$exchangeName market not included {$market['symbol']}");
                }
            }
        } catch (Exception $e) {
            $Monolog->sendEntry('critical', sprintf("Get exchange %s symbols failed: %s", $exchangeName, $e->getMessage()));
        }
        if ($exchange != null) {
            $exchange->releaseExchangeResources();
        }
    }
    sleep(300);
}
