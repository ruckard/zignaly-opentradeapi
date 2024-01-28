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

use Zignaly\exchange\exceptions;
use ccxt;
use \ccxt\BadSymbol;
use \ccxt\InvalidAddress;
use Zignaly\exchange\zignalyratelimiter\RateLimitTrait;

class bitmex extends ccxt\bitmex {
    use RateLimitTrait;
    
    public function describe() {
        $parentDescribe = parent::describe();
        // add forcedOrders endpoint
        $parentDescribe['has']['fetchDepositAddress'] = true;
        return $parentDescribe;
    }

    public function __construct($options = array()) {
        parent::__construct($options);
    }


    public function fetch_deposit_address($code, $params = array ()) {
        $this->load_markets();
        $currency = $this->currency($code);
        $currencyId = isset($currency['id']) ? $currency['id'] : $code;
        if ($currencyId != 'XBT') {
            throw new BadSymbol($this->id . ' ' . $this->version . " fetchDepositAddress doesn't support " . $code . ', only accepts BTC');
        }
        $request = array(
            'currency' => 'XBt',
        );
        $response = $this->privateGetUserDepositAddress(array_merge($request, $params));
        if (($response === null) || !$response) {
            throw new InvalidAddress($this->id . ' fetchDepositAddress returned an empty $response.');
        }
        $address = $response;
        $this->check_address($address);
        return array(
            'currency' => $code,
            'address' => $this->check_address($address),
            'tag' => null,
            'info' => $response,
        );
    }
}