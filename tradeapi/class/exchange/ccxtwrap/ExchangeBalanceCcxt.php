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

namespace Zignaly\exchange\ccxtwrap;

use Zignaly\exchange\ExchangeBalance;
/**
 * {
 *    'info':  { ... },    // the original untouched non-parsed reply with details
 *
 *    //-------------------------------------------------------------------------
 *    // indexed by availability of funds first, then by currency
 *
 *    'free':  {           // money, available for trading, by currency
 *        'BTC': 321.00,   // floats...
 *        'USD': 123.00,
 *        ...
 *    },
 *
 *    'used':  { ... },    // money on hold, locked, frozen, or pending, by currency
 *
 *    'total': { ... },    // total (free + used), by currency
 *
 *    //-------------------------------------------------------------------------
 *    // indexed by currency first, then by availability of funds
 *
 *    'BTC':   {           // string, three-letter currency code, uppercase
 *        'free': 321.00   // float, money available for trading
 *        'used': 234.00,  // float, money on hold, locked, frozen or pending
 *        'total': 555.00, // float, total balance (free + used)
 *    },
 *
 *    'USD':   {           // ...
 *        'free': 123.00   // ...
 *        'used': 456.00,
 *        'total': 579.00,
 *    },
 *
 *    ...
 * }
 */

 class ExchangeBalanceCcxt implements ExchangeBalance {

    protected $ccxtResponse;
    public function __construct ($ccxtResponse) {
        $this->ccxtResponse = $ccxtResponse;
    }
    
    /**
     * Get ccxt response
     *
     * @return void
     */
    public function getCcxtResponse()
    {
        return $this->ccxtResponse;
    }
    /**
     * return array indexed by symbol with free balance
     *
     * @return array
     */
    public function getFreeAll () {
        return $this->ccxtResponse['free'];
    }
    /**
     * get free balance for symbol
     *
     * @param string $symbol
     * @return void
     */
    public function getFree (string $symbol) {
        if (!array_key_exists ($symbol, $this->ccxtResponse['free'])) {
            return null;
        }
        return $this->ccxtResponse['free'][$symbol];
    }
    /**
     * return array indexed by symbol with used balance
     *
     * @return array
     */
    public function getUsedAll () {
        return $this->ccxtResponse['used'];
    }
    /**
     * get used balance for symbol
     *
     * @param string $symbol
     * @return void
     */
    public function getUsed (string $symbol) {
        if (!array_key_exists ($symbol, $this->ccxtResponse['used'])) {
            return null;
        }
        return $this->ccxtResponse['used'][$symbol];
    }
    /**
     * return array indexed by symbol with total balance
     *
     * @return array
     */
    public function getTotalAll () {
        return $this->ccxtResponse['total'];
    }
    /**
     * get total balance for symbol
     *
     * @param string $symbol
     * @return void
     */
    public function getTotal (string $symbol) {
        if (!array_key_exists ($symbol, $this->ccxtResponse['total'])) {
            return null;
        }
        return $this->ccxtResponse['total'][$symbol];
    }
    /**
     * get array with 'used', 'total', 'free' balance for symbol
     *
     * @param string $symbol
     * @return void
     */
    public function getSymbolBalance (string $symbol) {
        if (!\array_key_exists ($symbol, $this->ccxtResponse)) {
            return null;
        }
        return $this->ccxtResponse[$symbol];
    }
    /**
     * get all balance
     */
    public function getAll (){
        return $this->ccxtResponse;
    }
    /**
     * Max withdraw amount
     *
     * @param string $symbol
     * @return float
     */
    public function getMaxWithdrawAmount(string $symbol)
    {
        if (!array_key_exists($symbol, $this->ccxtResponse['max_withdraw_amount'])) {
            return $this->getTotal($symbol);
        }
        return $this->ccxtResponse['max_withdraw_amount'][$symbol];
    }

 }