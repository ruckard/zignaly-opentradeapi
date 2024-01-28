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

use Zignaly\exchange\ExchangeOrder, 
    Zignaly\exchange\ExchangeOrderStatus,
    Zignaly\exchange\ExchangeTrade,
    Zignaly\exchange\ExchangeOrderType,
    Zignaly\exchange\ExchangeOrderSide;
/**
 * Exchange ccxt order
 * 
 * {
 *   'id':                '12345-67890:09876/54321', // string
 *   'datetime':          '2017-08-17 12:42:48.000', // ISO8601 datetime of 'timestamp' with milliseconds
 *   'timestamp':          1502962946216, // order placing/opening Unix timestamp in milliseconds
 *   'lastTradeTimestamp': 1502962956216, // Unix timestamp of the most recent trade on this order
 *   'status':     'open',         // 'open', 'closed', 'canceled'
 *   'symbol':     'ETH/BTC',      // symbol
 *   'type':       'limit',        // 'market', 'limit'
 *   'side':       'buy',          // 'buy', 'sell'
 *   'price':       0.06917684,    // float price in quote currency
 *   'stopPrice'    0.23           // float stop price in quote currency
 *   'amount':      1.5,           // ordered amount of base currency
 *   'filled':      1.1,           // filled amount of base currency
 *   'remaining':   0.4,           // remaining amount to fill
 *   'cost':        0.076094524,   // 'filled' * 'price' (filling price used where available)
 *   'trades':    [ ... ],         // a list of order trades/executions
 *   'fee': {                      // fee info, if available
 *       'currency': 'BTC',        // which currency the fee is (usually quote)
 *       'cost': 0.0009,           // the fee amount in that currency
 *       'rate': 0.002,            // the fee rate (if available)
 *   },
 *   'info': { ... },              // the original unparsed order structure as is
 * }
 */
class ExchangeOrderCcxt implements ExchangeOrder {
    protected $ccxtResponse;
    protected $ccxtTrades;
    public function __construct ($ccxtResponse, $ccxtTrades = null) {
        $this->ccxtResponse = $ccxtResponse;
        $this->ccxtTrades = $ccxtTrades;
        if (($ccxtTrades == null) && isset($ccxtResponse['trades']) && is_array($ccxtResponse['trades'])){
            $this->ccxtTrades = array();
            foreach ($ccxtResponse['trades'] as $trade){
                $this->ccxtTrades[] = new ExchangeTradeCcxt ($trade);
            }
        }
    }
    public function getCcxtResponse(){
        return $this->ccxtResponse;
    }
    /**
     * get order id
     *
     * @return string
     */
    public function getId () {
        return $this->ccxtResponse['id'];
    }

    /**
     * Get reduce only flag.
     *
     * @return mixed
     */
    public function getReduceOnly()
    {
        if (!isset($this->ccxtResponse['reduceOnly'])) {
            return '';
        }

        return $this->ccxtResponse['reduceOnly'];
    }

    /**
     * ISO8601 datetime of 'timestamp' with milliseconds
     *
     * @return string
     */
    public function getStrDateTime (){
        return $this->ccxtResponse['datetime'];
    }
    /**
     * order placing/opening Unix timestamp in milliseconds
     *
     * @return long
     */
    public function getTimestamp () {
        return $this->ccxtResponse['timestamp'];
    }
    /**
     * Undocumented function
     *
     * @return long
     */
    public function getLastTradeTimestamp () {
        return $this->ccxtResponse['lastTradeTimestamp'];
    }
    /**
     * order status
     *
     * @return string
     */
    public function getStatus () {
        if (!isset($this->ccxtResponse['status'])){
            return null;
        }
        return ExchangeOrderStatus::fromCcxt ($this->ccxtResponse['status']);
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
     * order type
     *
     * @return string
     */
    public function getType () {
        if (!isset($this->ccxtResponse['type'])){
            return null;
        }
        return ExchangeOrderType::fromCcxt ($this->ccxtResponse['type']);
    }
    /**
     * order side
     *
     * @return string
     */
    public function getSide () {
        if (!isset($this->ccxtResponse['side'])){
            return null;
        }
        return ExchangeOrderSide::fromCcxt ($this->ccxtResponse['side']);
    }
    /**
     * float price in quote currency
     *
     * @return float
     */
    public function getPrice () {
        if (isset($this->ccxtResponse['average'])) {
            return $this->ccxtResponse['average'];
        }
        return $this->ccxtResponse['price'];
    }
    /**
     * float stop price in quote currency
     *
     * @return float
     */
    public function getStopPrice()
    {
        if (isset($this->ccxtResponse['stopPrice'])) {
            return $this->ccxtResponse['stopPrice'];
        }

        return 0.0;
    }
    /**
     * ordered amount of base currency
     *
     * @return float
     */
    public function getAmount () {
        return $this->ccxtResponse['amount'];
    }
    /**
     * filled amount of base currency
     *
     * @return float
     */
    public function getFilled () {
        return $this->ccxtResponse['filled'];
    }
    /**
     * remaining amount to fill
     *
     * @return float
     */
    public function getRemaining () {
        return $this->ccxtResponse['remaining'];
    }
    /**
     * 'filled' * 'price' (filling price used where available)
     *
     * @return float
     */
    public function getCost () {
        return $this->ccxtResponse['cost'];
    }
    /**
     * a list of order trades/executions
     *
     * @return ExchangeTrade[]
     */
    public function getTrades () {
        return $this->ccxtTrades;
    }
    public function setTrades ($ccxtTrades){
        $this->ccxtTrades = $ccxtTrades;
    }
    /**
     * which currency the fee is (usually quote)
     *
     * @return string
     */
    public function getFeeCurrency () {
        if ($this->ccxtResponse['fee'] == null) return null;
        return $this->ccxtResponse['fee']['currency'];
    }
    /**
     * the fee amount in that currency
     *
     * @return float
     */
    public function getFeeCost () {
        if ($this->ccxtResponse['fee'] == null) return null;
        return $this->ccxtResponse['fee']['cost'];
    }
    /**
     * the fee rate (if available)
     *
     * @return float
     */
    public function getFeeRate() {
        if ($this->ccxtResponse['fee'] == null) return null;
        return $this->ccxtResponse['fee']['rate'];
    }

    /**
     * Get zignaly client id sent on order creation
     *
     * @return string
     */
    public function getZignalyClientId()
    {
        if (isset($this->ccxtResponse['zignalyClientId'])) {
            return $this->ccxtResponse['zignalyClientId'];
        }

        return null;
    }
    /**
     * Get received client id from exchange
     *
     * @return string
     */
    public function getRecvClientId()
    {
        if (isset($this->ccxtResponse['recvClientId'])) {
            return $this->ccxtResponse['recvClientId'];
        }

        return null;
    }

    /**
     * original request from exchange
     *
     * @return []
     */
    public function getInfo() {
        return $this->ccxtResponse['info'];
    }
}

