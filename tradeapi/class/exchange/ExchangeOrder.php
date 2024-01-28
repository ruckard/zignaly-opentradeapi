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

namespace Zignaly\exchange;

// use ExchangeOrderStatus, ExchangeTrade;

interface ExchangeOrder {
    
    /**
     * get order id
     *
     * @return string
     */
    public function getId ();
    /**
     * ISO8601 datetime of 'timestamp' with milliseconds
     *
     * @return string
     */
    public function getStrDateTime ();
    /**
     * order placing/opening Unix timestamp in milliseconds
     *
     * @return long
     */
    public function getTimestamp ();
    /**
     * Undocumented function
     *
     * @return long
     */
    public function getLastTradeTimestamp ();
    /**
     * order status
     *
     * @return ExchangeOrderStatus
     */
    public function getStatus ();
    /**
     * symbol
     *
     * @return string
     */
    public function getSymbol ();
    /**
     * order type
     *
     * @return ExchangeOrderType
     */
    public function getType ();
    /**
     * order side
     *
     * @return ExchangeOrderSide
     */
    public function getSide ();
    /**
     * float price in quote currency
     *
     * @return float
     */
    public function getPrice ();
    /**
     * float stop lprice in quote currency
     *
     * @return float
     */
    public function getStopPrice ();
    /**
     * ordered amount of base currency
     *
     * @return float
     */
    public function getAmount ();
    /**
     * filled amount of base currency
     *
     * @return float
     */
    public function getFilled ();
    /**
     * remaining amount to fill
     *
     * @return float
     */
    public function getRemaining ();
    /**
     * 'filled' * 'price' (filling price used where available)
     *
     * @return float
     */
    public function getCost ();
    /**
     * a list of order trades/executions
     *
     * @return ExchangeTrade[]
     */
    public function getTrades ();
    /**
     * which currency the fee is (usually quote)
     *
     * @return string
     */
    public function getFeeCurrency ();
    /**
     * the fee amount in that currency
     *
     * @return float
     */
    public function getFeeCost ();
    /**
     * the fee rate (if available)
     *
     * @return float
     */
    public function getFeeRate();
    /**
     * Get zignaly client id sent
     *
     * @return string
     */
    public function getZignalyClientId();
    /**
     * Get received client id from exchange
     *
     * @return string
     */
    public function getRecvClientId();
    /**
     * original request from exchange
     *
     * @return []
     */
    public function getInfo();
}