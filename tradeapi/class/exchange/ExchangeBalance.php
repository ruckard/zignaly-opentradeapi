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

interface ExchangeBalance {
    /**
     * return array indexed by symbol with free balance
     *
     * @return array
     */
    public function getFreeAll ();
    /**
     * get free balance for symbol
     *
     * @param string $symbol
     * @return void
     */
    public function getFree (string $symbol);
    /**
     * return array indexed by symbol with used balance
     *
     * @return array
     */
    public function getUsedAll ();
    /**
     * get used balance for symbol
     *
     * @param string $symbol
     * @return void
     */
    public function getUsed (string $symbol);
    /**
     * return array indexed by symbol with total balance
     *
     * @return array
     */
    public function getTotalAll ();
    /**
     * get total balance for symbol
     *
     * @param string $symbol
     * @return void
     */
    public function getTotal (string $symbol);
    /**
     * get array with 'used', 'total', 'free' balance for symbol
     *
     * @param string $symbol
     * @return void
     */
    public function getSymbolBalance (string $symbol);
    /**
     * get all balance
     */
    public function getAll ();
    /**
     * Max withdraw amount
     *
     * @param string $symbol
     * @return float
     */
    public function getMaxWithdrawAmount(string $symbol);
}