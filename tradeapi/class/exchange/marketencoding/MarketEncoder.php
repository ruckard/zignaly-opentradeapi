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

use Zignaly\service\entity\ZignalyMarketData;

/**
 * Market encoder
 * 
 */
interface MarketEncoder {

    /**
     * Check it this market has to be imported into the zignaly market set
     * for this exchange
     *
     * @param [] $market
     * 
     * @return boolean
     */
    public function validMarket4Zignaly($market);
    /**
     * Create zignaly market from ccxt market
     *
     * @param [] $market
     * @return []
     */
    public function createMarketFromCcxtMarket($market);
    /**
     * exchange code for coinray
     *
     * @return void
     */
    public function coinrayExchange();
    /**
     * get coinray base
     *
     * @param array $market
     * @return string
     */
    public function coinrayBase($market);
    /**
     * get coinray quote
     *
     * @param array $market
     * @return string
     */
    public function coinrayQuote($market);
    /**
     * clean zignaly symbol
     *
     * @param string $symbol
     * @return string
     */
    public function withoutSlash($symbol);
    /**
     * get zignaly symbol from ccxt symbol
     *
     * @param string $symbol
     * @param array $ccxtMarket
     * @return string
     */
    public function fromCcxt($symbol, $ccxtMarket = null);
    /**
     * get ccxt symbol from zignaly
     *
     * @param string $symbol
     * @return string
     * @throws Exception if simbol not found
     */
    public function toCcxt($symbol);

    /**
     * get base and quote from symbol
     *
     * @param string $pair
     * @param boolean $returnAsAssociativeArray
     * @return string|array|bool
     */
    public function getBaseQuote(string $pair, bool $returnAsAssociativeArray = true);

    /**
     * Get base used in zignaly for this market
     *
     * @param ZignalyMarketData $marketData
     * @return string
     */
    public function getBaseFromMarketData(ZignalyMarketData $marketData);
    /**
     * Get quote used in zignaly for this market
     *
     * @param ZignalyMarketData $marketData
     * @return string
     */
    public function getQuoteFromMarketData(ZignalyMarketData $marketData);

    /**
     * Get symbol used in zignaly for this market
     *
     * @param ZignalyMarketData $marketData
     * @return string
     */
    public function getZignalySymbolFromMarketData(ZignalyMarketData $marketData);

    /**
     * Get markets
     *
     * @param boolean $force
     * @return array
     */
    public function loadMarkets($force = false);

    /**
     * Get indexes from markets
     *
     * @param boolean $force
     * @return array
     */
    public function loadIndexes($force = false);

    /**
     * Get exchange name of market encoder
     *
     * @return string
     */
    public function getExchangeName();
    /**
     * Translate asset to using it inside zingaly
     *
     * @param string $asset asset from ccxt
     * 
     * @return string
     */
    public function translateAsset($asset);

    /**
     * Get zignaly symbol from Zignaly id (pair)
     *
     * @param string $zignalyId zignalyid
     * @return string
     */
    public function getZignalySymbolFromZignalyId($zignalyId);

    /**
     * Get market quote for calc position size
     *
     * @param string $zignalyId zignaly id
     * 
     * @return string
     */
    public function getMarketQuote4PositionSize($zignalyId);

    /**
     * Get valid quote assets for this exchange
     *
     * @return string[]
     */
    public function getValidQuoteAssets();
    /**
     * Get market quote for position exchange settings
     *
     * @param string $zignalyId zignaly id
     * 
     * @return string
     */
    public function getMarketQuote4PositionSizeExchangeSettings($zignalyId);
    /**
     * Get valid quote assets for this exchange for exchange settings
     *
     * @return string[]
     */
    public function getValidQuoteAssets4PositionSizeExchangeSettings();
    /**
     * Get multiplier for symbol
     *
     * @param string $zignalyId zignaly pair
     * 
     * @return float
     */
    public function getMultiplier($zignalyId);
    /**
     * Is inverse
     *
     * @param string $zignalyId zignaly pair
     * 
     * @return boolean
     */
    public function isInverse($zignalyId);
    /**
     * Get short symbol
     *
     * @param string $zignalyId zignaly pair
     * 
     * @return string
     */
    public function getShort($zignalyId);
    /**
     * Get trade view symbol
     *
     * @param string $zignalyId zignaly pair
     * 
     * @return string
     */
    public function getTradeViewSymbol($zignalyId);
    /**
     * Get max leverage
     *
     * @param string $zignalyId zignaly pair
     * 
     * @return string
     */
    
    public function getMaxLeverage($zignalyId);
    /**
     * Get reference symbol for pair
     *
     * @param string $zignalyId
     * 
     * @return string
     */
    public function getReferenceSymbol4Zignaly($zignalyId);
    /**
     * Is quanto
     *
     * @param string $zignalyId zignaly pair
     * 
     * @return boolean
     */
    public function isQuanto($zignalyId);

    /**
     * Get market for zignaly id (pair)
     *
     * @param string $signalyId
     * 
     * @return ZignalyMarketData
     */
    public function getMarket($zignalyId);
    /**
     * Get market for ccxt symbol
     *
     * @param string $symbol
     * 
     * @return ZignalyMarketData
     */
    public function getMarketFromCcxtSymbol($symbol);
}