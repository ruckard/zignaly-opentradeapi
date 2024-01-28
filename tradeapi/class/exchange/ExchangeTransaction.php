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

interface ExchangeTransaction {
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getId();
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getTxId();
    /**
     * Undocumented function
     *
     * @return long
     */
    public function getTimestamp();
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getDatetime();
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getAddress();
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getTag();
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getType();
    /**
     * Undocumented function
     *
     * @return float
     */
    public function getAmount();
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getCurrency();
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getStatus();
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getUpdated();
    /**
     * Undocumented function
     *
     * @return float
     */
    public function getFee();
}