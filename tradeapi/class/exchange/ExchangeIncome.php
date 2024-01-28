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

interface ExchangeIncome {
    /**
     * get original response
     *
     * @return []
     */
    public function getInfo();
    /**
     * Get symbol
     *
     * @return string
     */
    public function getSymbol();
    /**
     * Get income type @see ExchangeIncomeType
     *
     * @return string
     */
    public function getIncomeType();
    /**
     * Get income
     *
     * @return float
     */
    public function getIncome();
    /**
     * Get asset
     *
     * @return string
     */
    public function getAsset();
    /**
     * Get timestamp
     *
     * @return int
     */
    public function getTimestamp();
    /**
     * Get datetime
     *
     * @return string
     */
    public function getDateTime();
    /**
     * Get income info
     *
     * @return int
     */
    public function getIncomeInfo();
    /**
     * Get Tran id
     *
     * @return int
     */
    public function getTranId();
    /**
     * Get trade id
     *
     * @return int
     */
    public function getTradeId();

}