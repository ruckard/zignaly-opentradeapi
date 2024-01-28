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

use Zignaly\exchange\ccxtwrap\exchanges\Binancefutures;
use Zignaly\exchange\ZignalyExchangeCodes;

/**
 * Market encoder for Binancefutures exchange.
 *
 * @package Zignaly\exchange\marketencoding
 */
class BinancefuturesMarketEncoder extends BinanceMarketEncoder
{
    /** @var MarketDataCache */
    static $marketCache;

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
        return isset($market['type']) && ('future' === $market['type'])
            && (!isset($market['info'])
                || !isset($market['info']['contractType'])
                || ('PERPETUAL' == $market['info']['contractType'])
        );
    }
    /**
     * @inheritdoc
     */
    public function toCcxt($symbol) {
        $ccxtSymbol = $this->symbolFromZig($symbol);

        if ($ccxtSymbol == null){
            throw new \Exception("Zignaly symbol ".$symbol. " not found in ".ZignalyExchangeCodes::ZignalyBinanceFutures);
        }

        return $ccxtSymbol;
    }

    /**
     * @inheritDoc
     */
    public function getExchangeName(){
        return ZignalyExchangeCodes::ZignalyBinanceFutures;
    }
    /**
     * @inheritDoc
     */
    public function createMarketFromCcxtMarket($market)
    {
        $m = parent::createMarketFromCcxtMarket($market);
        $m['maxLeverage'] = 125;
        return $m;
    }

}