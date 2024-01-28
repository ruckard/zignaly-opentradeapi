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

// use ExchangeTradeTakerOrMaker, ExchangeOrderType, ExchangeOrderSide;

interface ExchangeTrade {
    /**
     * string trade id
     *
     * @return string
     */
    public function getId ();
    /**
     * Unix timestamp in milliseconds
     *
     * @return long
     */
    public function getTimestamp ();
    /**
     * ISO8601 datetime with milliseconds
     *
     * @return string
     */
    public function getStrDateTime ();
    /**
     * symbol
     *
     * @return string
     */
    public function getSymbol ();
    /**
     * string order id or null
     *
     * @return string
     */
    public function getOrderId ();
    /**
     * order type
     *
     * @return ExchangeOrderType
     */
    public function getType ();
    /**
     * side
     *
     * @return ExchangeOrderSide
     */
    public function getSide ();
    /**
     * taker or maker
     *
     * @return ExchangeTradeTakerMaker
     */
    public function getTakerOrMaker ();
    /**
     * is taker trade
     *
     * @return boolean
     */
    public function isTaker();
    /**
     * is maker trade
     *
     * @return boolean
     */
    public function isMaker();
    /**
     * price
     *
     * @return float
     */
    public function getPrice ();
    /**
     * amount
     *
     * @return float
     */
    public function getAmount ();
    /**
     * cost
     *
     * @return float
     */
    public function getCost ();
    /**
     * fee cost
     *
     * @return float
     */
    public function getFeeCost ();
    /**
     * fee currency
     *
     * @return string
     */
    public function getFeeCurrency ();
    /**
     * fee rate
     *
     * @return float
     */
    public function getFeeRate ();
}