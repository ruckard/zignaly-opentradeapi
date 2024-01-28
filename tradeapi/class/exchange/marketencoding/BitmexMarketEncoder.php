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

use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\service\entity\ZignalyMarketData;

class BitmexMarketEncoder extends BaseMarketEncoder
{
    public function __construct()
    {
        parent::__construct();
    }
    /**
     * @inheritDoc
     */
    public function createMarketFromCcxtMarket($market)
    {
        $m = parent::createMarketFromCcxtMarket($market);
        $m['short'] = $this->getZignalySymbol($market['id']);
        $m['tradeViewSymbol'] = $m['short'];
        $m['zignalyId'] = $m['short'];
        $m['multiplier'] = isset($market['info']['multiplier']) ?
            abs($market['info']['multiplier']/100000000) : 0;
        $m['maxLeverage'] = isset($market['info']['initMargin']) ?
            1 / $market['info']['initMargin'] : 0;
        $m['inverse'] = !empty($market['info']['isInverse']);
        $m['quanto'] = !empty($market['info']['isQuanto']);
        if (isset($market['info']['referenceSymbol'])) {
            $m['referenceSymbol'] = $market['info']['referenceSymbol'];
        }

        $m['unitsInvestment'] = 'XBT';
        if ($m['inverse']) {
            // inverse
            $m['unitsAmount'] = $market['quote'];
            $m['contractType'] = 'inverse';
        } else if ($m['quanto']) {
            // quanto
            $m['unitsAmount'] = 'Cont';
            $m['contractType'] = 'quanto';
        } else {
            // linear
            $m['unitsAmount'] = $market['base'];
            $m['contractType'] = 'linear';
        }
        
        return $m;
    }
    /**
     * exchange code for coinray
     *
     * @return void
     */
    public function coinrayExchange() {
        return "BTMX";
    }

    /**
     * get zignaly symbol from ccxt symbol
     *
     * @param string $symbol
     * @param array $ccxtMarketData
     * @return string
     */
    public function fromCcxt($symbol, $ccxtMarket = null){
        if ($ccxtMarket != null) {
            return $this->getZignalySymbol($ccxtMarket['id']);
        }

        $zigSymbol = $this->symbolFromCcxt($symbol);

        if ($zigSymbol == null){
            throw new \Exception("Ccxt symbol ".$symbol. " in ".ZignalyExchangeCodes::ZignalyBitmex);
        }

        return $zigSymbol;
    }

    /**
     * @inheritdoc
     */
    public function toCcxt($symbol) {
        $ccxtSymbol = $this->symbolFromZig($symbol);

        if ($ccxtSymbol == null){
            throw new \Exception("Zignaly symbol ".$symbol. " not found in ".ZignalyExchangeCodes::ZignalyBitmex);
        }

        return $ccxtSymbol;
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
            } catch (\Exception $ex){
                return false;
            }
        }
        $base = $market->getBaseId();
        $quote = $market->getQuoteId();
        return $returnAsAssociativeArray ? ['base' => $base, 'quote' => $quote] : [$base, $quote];
    }

    /**
     * @inheritDoc
     */
    public function getBaseFromMarketData(ZignalyMarketData $marketData)
    {
        return $marketData->getBaseId();
    }

    /**
     * @inheritDoc
     */
    public function getQuoteFromMarketData(ZignalyMarketData $marketData)
    {
        return $marketData->getQuoteId();
    }

    /**
     * @inheritDoc
     */
    public function getZignalySymbolFromMarketData(ZignalyMarketData $marketData)
    {
        return $this->getZignalySymbol($marketData->getId());
    }

    private function getZignalySymbol($id)
    {
        return strtoupper(trim($id));
    }

    /**
     * @inheritDoc
     */
    public function getExchangeName()
    {
        return ZignalyExchangeCodes::ZignalyBitmex;
    }
    /**
     * @inheritDoc
     */
    public function translateAsset($asset)
    {
        return (strtoupper($asset) == "BTC") ? 'XBT' : $asset;
    }
    /**
     * @inheritDoc
     */
    public function getReferenceSymbol4Zignaly($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return null;
        }
        return $market->getReferenceSymbol();
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

        return $market->getId();
    }
    /**
     * @inheritDoc
     */
    public function getMarketQuote4PositionSize($zignalyId)
    {
        return 'XBT'; 
    }

    /**
     * @inheritdoc
     */
    public function getValidQuoteAssets()
    {
        return ['XBT'];
    }
    /**
     * Get market quote for position exchange settings
     *
     * @param string $zignalyId zignaly id
     * 
     * @return string
     */
    public function getMarketQuote4PositionSizeExchangeSettings($zignalyId)
    {
        return 'BTC'; 
    }

    /**
     * Get valid quote assets for this exchange for exchange settings
     *
     * @return string[]
     */
    public function getValidQuoteAssets4PositionSizeExchangeSettings()
    {
        return ['BTC'];
    }
    
}