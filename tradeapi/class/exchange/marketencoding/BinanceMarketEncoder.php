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

class BinanceMarketEncoder extends BaseMarketEncoder
{
    public function __construct()
    {
        parent::__construct();
    }

     /**
     * Exchange code for coinray
     *
     * @return void
     */
    public function coinrayExchange()
    {
        return "BINA";
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
        if ($market['baseId'] === 'BCHSV') {
            return 'BSV';
        }
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
     * @inheritdoc
     */
    public function toCcxt($symbol)
    {
        $ccxtSymbol = $this->symbolFromZig($symbol);

        if (null == $ccxtSymbol) {
            throw new \Exception(
                'Zignaly symbol '.$symbol. ' not found in '
                .ZignalyExchangeCodes::ZignalyBinance
            );
        }

        return $ccxtSymbol;
    }

    /**
     * @inheritDoc
     */
    public function getExchangeName()
    {
        return ZignalyExchangeCodes::ZignalyBinance;
    }

    /**
     * @inheritdoc
     */
    public function getValidQuoteAssets()
    {
        $validQuotes = parent::getValidQuoteAssets();

        if (!in_array('BNB', $validQuotes)) {
            $validQuotes[] = 'BNB';
        }

        return $validQuotes;
    }
    /**
     * Create zignaly market from ccxt market
     *
     * @param [] $market
     * @return []
     */
    public function createMarketFromCcxtMarket($market)
    {
        $ret = parent::createMarketFromCcxtMarket($market);
        if ('YOYOBTC' === $ret['zignalyId']) {
            $ret['zignalyId'] = 'YOYOWBTC';
        }

        return $ret;
    }

}
