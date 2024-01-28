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

/**
 * Class VcceMarketEncoder
 * @package Zignaly\exchange\marketencoding
 */
class VcceMarketEncoder extends BaseMarketEncoder
{

    /**
     * @inheritDoc
     */
    public function createMarketFromCcxtMarket($market)
    {
        $m = parent::createMarketFromCcxtMarket($market);
        // $m['zignalyId'] = str_replace('_', '', $market['id']);
        $m['zignalyId'] = strtoupper(str_replace('_', '', $market['id']));
        return $m;
    }
    /**
     * @return string|void
     */
    public function coinrayExchange()
    {
        return null;
    }

    /**
     * @param string $symbol
     * @return string|void
     * @throws \Exception
     */
    public function toCcxt($symbol)
    {
        $ccxtSymbol = $this->symbolFromZig($symbol);

        if (null === $ccxtSymbol) {
            throw new \Exception('Zignaly symbol '.$symbol. ' not found in ' .ZignalyExchangeCodes::ZignalyVcce);
        }

        return $ccxtSymbol;
    }

    /**
     * @return string
     */
    public function getExchangeName(): string
    {
        return ZignalyExchangeCodes::ZignalyVcce;
    }
}
