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

namespace Zignaly\exchange\marketencoding;

use TradeApiClient;
use Zignaly\exchange\ExchangeFactory;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\service\entity\ZignalyMarketData;
use Zignaly\Process\DIContainer;

/**
 * Base market encoder
 * 
 * $market array keys:
 * id:       'YOYOBTC',
 * symbol:   'YOYOW/BTC',
 * baseId:   'YOYO',
 * quoteId:  'BTC',
 * base:     'YOYOW',
 * quote:    'BTC'
 */
abstract class BaseMarketEncoder implements MarketEncoder {
    /**
     * @var TradeApiClient
     */
    private $_tradeApiClient = null;
    /**
     * Process memory cache.
     *
     * @var object|\Symfony\Component\Cache\Adapter\ArrayAdapter|null
     */
    private $_arrayCache = null;
    /**
     * BaseMarketEncoder constructor
     */
    public function __construct()
    {
    }

    /**
     * Get encoder for market
     *
     * @param string $exchange      exchange name
     * @param array  $spotORFutures 'spot'|'futures'
     * 
     * @return MarketEncoder
     */
    public static function newInstance($exchange, $spotORFutures = 'spot')
    {
        $exchange = ZignalyExchangeCodes::
            getExchangeFromCaseInsensitiveString($exchange);
        $realExchangeName = ZignalyExchangeCodes::getRealExchangeName($exchange);
        $tradesExchangeName = ZignalyExchangeCodes::getExchangeFromCaseInsensitiveStringWithType($realExchangeName, $spotORFutures);
        $clazz = __NAMESPACE__.'\\'.ucfirst(strtolower($tradesExchangeName))
            ."MarketEncoder";
        return new $clazz();
    }

    /**
     * Check it this market has to be imported into the zignaly market set
     * for this exchange
     *
     * @param [] $market
     * 
     * @return boolean
     */
    public function validMarket4Zignaly($market)
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function createMarketFromCcxtMarket($market)
    {
        return [
            'id' => $market['id'],
            'symbol' => $market['symbol'],
            'base' => $market['base'],
            'quote' => $market['quote'],
            'baseId' => $market['baseId'],
            'quoteId' => $market['quoteId'],
            'precision' => $market['precision'],
            'limits' => $market['limits'],
            'active' => !empty($market['active']),
            'multiplier' => 1,
            
            'short' => $market['symbol'],
            'tradeViewSymbol' =>  $market['baseId'].$market['quoteId'],
            'zignalyId' => $market['id'],

            'unitsInvestment' => $market['quote'],
            'unitsAmount' => $market['base']
        ];
    }

    /**
     * Get coinray base
     *
     * @param array $market market
     * 
     * @return string
     */
    public function coinrayBase($market)
    {
        return $market['baseId'];
    }

    /**
     * Get coinray quote
     *
     * @param array $market market
     * 
     * @return string
     */
    public function coinrayQuote($market)
    {
        return $market['quoteId'];
    }

    /**
     * Clean zignaly symbol
     *
     * @param string $symbol ccxt or zignaly symbols
     * 
     * @return string
     */
    public function withoutSlash($symbol)
    {
        return strtoupper(preg_replace("/[^A-Za-z0-9 ]/", '', trim($symbol)));
    }

    /**
     * Get zignaly id from ccxt symbol
     *
     * @param string $symbol     ccxt symbol to be converted to zingnaly id
     * @param array  $ccxtMarket ccxt market array
     * 
     * @return string
     */
    public function fromCcxt($symbol, $ccxtMarket = null)
    {
        return $this->withoutSlash($symbol);
    }

    /**
     * @inheritDoc
     */
    public function getBaseQuote(string $pair, bool $returnAsAssociativeArray = true)
    {
        $market = $this->marketFromZig($pair);
        if ($market == null) {
            // try with ccxt symbol
            try {
                $ccxtSymbol = $this->fromCcxt($pair);
                $market = $this->marketFromZig($ccxtSymbol);
                if ($market == null) return false;
            } catch (\Exception $ex){
                return false;
            }
        }
        $base = $market->getBase();
        $quote = $market->getQuote();
        return $returnAsAssociativeArray ? ['base' => $base, 'quote' => $quote] : [$base, $quote];
    }

    /**
     * @inheritDoc
     */
    public function getBaseFromMarketData(ZignalyMarketData $marketData)
    {
        return $marketData->getBase();
    }

    /**
     * @inheritDoc
     */
    public function getQuoteFromMarketData(ZignalyMarketData $marketData)
    {
        return $marketData->getQuote();
    }

    /**
     * @inheritDoc
     */
    public function getZignalySymbolFromMarketData(ZignalyMarketData $marketData)
    {
        return $marketData->getBase().$marketData->getQuote();
    }

    /**
     * Get Key for storing markets data in array cache
     *
     * @return string
     */
    protected function getKey4Markets()
    {
        return strtolower($this->getExchangeName());
    }
    /**
     * Get Key for storing markets indexes in array cache
     *
     * @return string
     */
    protected function getKey4Indexes()
    {
        return strtolower($this->getKey4Markets()."-index");
    }

    /**
     * Get TradeApiClient
     *
     * @return TradeApiClient
     */
    protected function getTradeApiClient()
    {
        if ($this->_tradeApiClient == null) {
            $container = DIContainer::getContainer();
            $this->_tradeApiClient = $container->get('TradeApiClient');
        }
        return $this->_tradeApiClient;
    }

    /**
     * Get arraycache
     *
     * @return \Symfony\Component\Cache\Adapter\ArrayAdapter
     */
    protected function getArrayCache()
    {
        if ($this->_arrayCache == null) {
            $container = DIContainer::getContainer();
            $this->_arrayCache = $container->get('longTermArrayCache');
        }
        return $this->_arrayCache;
    }

    /**
     * @inheritDoc
     */
    public function loadMarkets($force = false)
    {
        $arrayCache = $this->getArrayCache();

        $cache = $arrayCache->getItem($this->getKey4Markets());

        if (!$force && $cache->isHit()) {
            return $cache->get();
        }

        $arrayCache->deleteItem($this->getKey4Indexes());
        //$markets = $this->getTradeApiClient()->getExchangeMarketData($this->getExchangeName());

        //if (empty($markets)) {
        list ($exchangeName, $exchangeType) = ZignalyExchangeCodes::getExchangeNameAndTypeFromZignalyExchangeCodes(
            $this->getExchangeName()
        );
        $temporalExchange = ExchangeFactory::createFromNameAndType(
            $exchangeName,
            $exchangeType,
            [
                'enableRateLimit' => true,
            ]
        );
        $markets = [];
        try {
            $temporalMarkets = $temporalExchange->loadMarkets();
            foreach ($temporalMarkets as $market) {
                if ($this->validMarket4Zignaly($market)) {
                    $markets[] = $this->createMarketFromCcxtMarket($market);
                }
            }
            $temporalExchange->releaseExchangeResources();
        } catch (\Exception $ex) {
            $temporalExchange->releaseExchangeResources();
            }
            
        //}

        $cache->set($markets);
        $arrayCache->save($cache);
        return $markets;
    }

    /**
     * @inheritDoc
     */
    public function loadIndexes($force = false)
    {
        $arrayCache = $this->getArrayCache();
        $cache = $arrayCache->getItem($this->getKey4Markets());

        if (!$force && $cache->isHit()) {
            return $cache->get();
        }
        $markets = $this->loadMarkets($force);
        $byZig = array();
        $byCcxt = array();

        $exchange = $this->getExchangeName();
        foreach ($markets as $market) {
            $marketC = new ZignalyMarketData($exchange, $market);
            $zignalyId = $this->getZignalySymbolFromMarketData($marketC);
            $byZig[$zignalyId] = array(
                'ccxtSymbol' => $marketC->getSymbol(),
                'market' => $marketC
            );

            $byCcxt[$marketC->getSymbol()] = $zignalyId;
        }

        $indexes = [
            'byZig' => $byZig,
            'byCcxt' => $byCcxt
        ];
        $cache->set($indexes);
        $arrayCache->save($cache);
        return $indexes;
    }

    /**
     * Get ccxt symbol from zignaly pair
     *
     * @param string $pair zignaly pair
     * 
     * @return void
     */
    protected function symbolFromZig($pair)
    {
        $indexes = $this->loadIndexes();
        $bitmexMarketByZignaly = $indexes['byZig'];
        $cleanPair = trim($pair);

        if (array_key_exists($cleanPair, $bitmexMarketByZignaly)) {
            return $bitmexMarketByZignaly[$cleanPair]['ccxtSymbol'];
        }

        return null;
    }

    /**
     * Get Zignaly pair from ccxt symbol
     *
     * @param string $symbol ccxt symbol
     * 
     * @return void
     */
    protected function symbolFromCcxt($symbol)
    {
        $indexes = $this->loadIndexes();
        $bitmexMarketByCcxt = $indexes['byCcxt'];
        $cleanSymbol = trim($symbol);

        if (array_key_exists($cleanSymbol, $bitmexMarketByCcxt)) {
            return $bitmexMarketByCcxt[$cleanSymbol];
        }

        return null;
    }
    /**
     * Get market data from symbol
     *
     * @param string $pair zignaly pair
     * 
     * @return ZignalyMarketData
     */
    protected function marketFromZig($pair)
    {
        $indexes = $this->loadIndexes();
        $bitmexMarketByZignaly = $indexes["byZig"];
        $cleanPair = trim($pair);

        if (array_key_exists($cleanPair, $bitmexMarketByZignaly)) {
            return $bitmexMarketByZignaly[$cleanPair]['market'];
        }

        return null;
    }
    /**
     * @inheritDoc
     */
    public function translateAsset($asset)
    {
        return $asset;
    }
    /**
     * @inheritDoc
     */
    public function getZignalySymbolFromZignalyId($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return null;
        }
        return $market->getBase().'/'.$market->getQuote();
    }
    /**
     * @inheritDoc
     */
    public function getReferenceSymbol4Zignaly($zignalyId)
    {
        return null;
    }
    /**
     * @inheritDoc
     */
    public function getMarketQuote4PositionSize($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return null;
        }
        return $market->getQuote(); 
    }

    /**
     * @inheritdoc
     */
    public function getValidQuoteAssets()
    {
        $indexes = $this->loadIndexes();
        $bitmexMarketByZignaly = $indexes["byZig"];

        $validQuotes = [];
        foreach ($bitmexMarketByZignaly as $zigPair => $data) {
            $market = $data['market'];
            $validQuotes[] = $market->getQuote();
        }

        return $validQuotes;
    }
    /**
     * @inheritDoc
     */
    public function getMarketQuote4PositionSizeExchangeSettings($zignalyId)
    {
        return $this->getMarketQuote4PositionSize($zignalyId);
    }

    /**
     * @inheritdoc
     */
    public function getValidQuoteAssets4PositionSizeExchangeSettings()
    {
        return $this->getValidQuoteAssets();
    }
    /**
     * @inheritDoc
     */
    public function getMultiplier($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return false;
        }
        return $market->getMultiplier();
    }
    /**
     * @inheritDoc
     */
    public function isInverse($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return false;
        }
        return $market->isInverse();
    }
    /**
     * @inheritDoc
     */
    public function getShort($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return false;
        }
        return $market->getShort();
    }
    /**
     * @inheritDoc
     */
    public function getTradeViewSymbol($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return false;
        }
        return $market->getTradeViewSymbol();
    }
    /**
     * @inheritDoc
     */
    public function getMaxLeverage($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return false;
        }
        return $market->getMaxLeverage();
    }
    /**
     * @inheritDoc
     */
    public function isQuanto($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return false;
        }
        return $market->isQuanto();
    }
    /**
     * Get market for zignaly id (pair)
     *
     * @param string $signalyId
     * 
     * @return ZignalyMarketData
     */
    public function getMarket($zignalyId)
    {
        return $this->marketFromZig($zignalyId);
    }
    /**
     * Get market for ccxt symbol
     *
     * @param string $symbol
     * 
     * @return ZignalyMarketData
     */
    public function getMarketFromCcxtSymbol($symbol)
    {
        $zignalyId = $this->symbolFromCcxt($symbol);
        if (null  == $zignalyId) {
            return null;
        }
        return $this->marketFromZig($zignalyId);
    }
}