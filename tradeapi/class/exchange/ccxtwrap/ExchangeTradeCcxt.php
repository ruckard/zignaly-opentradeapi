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

use Zignaly\exchange\ExchangeTrade;
use Zignaly\exchange\ExchangeTradeTakerOrMaker;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\ExchangeTradeMakerOrTaker;

/**
 * ccxt trade
 * {
 *  'info':         { ... },                    // the original decoded JSON as is
 *   'id':           '12345-67890:09876/54321',  // string trade id
 *   'timestamp':    1502962946216,              // Unix timestamp in milliseconds
 *   'datetime':     '2017-08-17 12:42:48.000',  // ISO8601 datetime with milliseconds
 *   'symbol':       'ETH/BTC',                  // symbol
 *   'order':        '12345-67890:09876/54321',  // string order id or undefined/None/null
 *   'type':         'limit',                    // order type, 'market', 'limit' or undefined/None/null
 *   'side':         'buy',                      // direction of the trade, 'buy' or 'sell'
 *   'takerOrMaker': 'taker',                    // string, 'taker' or 'maker'
 *   'price':        0.06917684,                 // float price in quote currency
 *   'amount':       1.5,                        // amount of base currency
 *   'cost':         0.10376526,                 // total cost (including fees), `price * amount`
 *   'fee':          {                           // provided by exchange or calculated by ccxt
 *       'cost':  0.0015,                        // float
 *       'currency': 'ETH',                      // usually base currency for buys, quote currency for sells
 *       'rate': 0.002,                          // the fee rate (if available)
 *   },
 * }
 */
class ExchangeTradeCcxt implements ExchangeTrade {
    protected $ccxtResponse;
    public function __construct ($ccxtResponse) {
        $this->ccxtResponse = $ccxtResponse;
    }
    public function getCcxtResponse(){
        return $this->ccxtResponse;
    }
    /**
     * string trade id
     *
     * @return string
     */
    public function getId (){
        return $this->ccxtResponse['id'];
    }
    /**
     * Unix timestamp in milliseconds
     *
     * @return long
     */
    public function getTimestamp () {
        return $this->ccxtResponse['timestamp'];
    }
    /**
     * ISO8601 datetime with milliseconds
     *
     * @return string
     */
    public function getStrDateTime () {
        return $this->ccxtResponse['datetime'];
    }
    /**
     * symbol
     *
     * @return string
     */
    public function getSymbol () {
        return $this->ccxtResponse['symbol'];
    }
    /**
     * string order id or null
     *
     * @return string
     */
    public function getOrderId () {
        return $this->ccxtResponse['order'];
    }
    /**
     * order type
     *
     * @return ExchangeOrderType
     */
    public function getType () {
        return ExchangeOrderType::fromCcxt ($this->ccxtResponse['type']);
    }
    /**
     * side
     *
     * @return ExchangeOrderSide
     */
    public function getSide () {
        return ExchangeOrderSide::fromCcxt ($this->ccxtResponse['side']);
    }
    /**
     * taker or maker
     *
     * @return ExchangeTradeMakerOrTaker
     */
    public function getTakerOrMaker () {
        return ExchangeTradeMakerOrTaker::fromCcxt ($this->ccxtResponse['takerOrMaker']);
    }
    /**
     * is taker trade
     *
     * @return boolean
     */
    public function isTaker(){
        return $this->getTakerOrMaker() == ExchangeTradeMakerOrTaker::Taker;
    }
    /**
     * is maker trade
     *
     * @return boolean
     */
    public function isMaker(){
        return $this->getTakerOrMaker() == ExchangeTradeMakerOrTaker::Maker;
    }
    /**
     * price
     *
     * @return float
     */
    public function getPrice () {
        return $this->ccxtResponse['price'];
    }
    /**
     * amount
     *
     * @return float
     */
    public function getAmount () {
        return $this->ccxtResponse['amount'];
    }
    /**
     * cost
     *
     * @return float
     */
    public function getCost () {
        return $this->ccxtResponse['cost'];
    }
    /**
     * fee cost
     *
     * @return float
     */
    public function getFeeCost () {
        if (isset($this->ccxtResponse['fee']) && isset($this->ccxtResponse['fee']['cost'])){
            return $this->ccxtResponse['fee']['cost'];
        }
        return null;
    }
    /**
     * fee currency
     *
     * @return string
     */
    public function getFeeCurrency () {
        if (isset($this->ccxtResponse['fee']) && isset($this->ccxtResponse['fee']['currency'])){
            return $this->ccxtResponse['fee']['currency'];
        }
        return null;
    }
    /**
     * fee rate
     *
     * @return float
     */
    public function getFeeRate () {
        if (isset($this->ccxtResponse['fee']) && isset($this->ccxtResponse['fee']['rate'])){
            return $this->ccxtResponse['fee']['rate'];
        }
        return null;

    }
}