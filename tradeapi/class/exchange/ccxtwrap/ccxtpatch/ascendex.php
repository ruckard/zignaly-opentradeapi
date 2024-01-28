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

namespace Zignaly\exchange\ccxtwrap\ccxtpatch;

use ccxt;
use Zignaly\exchange\zignalyratelimiter\RateLimitTrait;

class ascendex extends ccxt\ascendex
{
    use RateLimitTrait;
    
    public function describe()
    {
        $parentDescribe = parent::describe();
        // add current test endpoint
        $parentDescribe['urls']['test'] = 'https://api-test.bitmax-sandbox.io';
        return $parentDescribe;
    }

    public function parse_order($order, $market = null)
    {
        $order = parent::parse_order($order, $market);
        // check if average is 0 to set to price, because in
        // ExchangeOrder->getOrder we are getting the average price
        // if defined (only Ascendex define this property in limit open orders)
        if (isset($order['average']) && 0 == $order['average']) {
            $order['average'] = $order['price'];
        }

        return $order;
    }
}
