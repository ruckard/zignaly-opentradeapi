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

interface ExchangeUserTransactionInfo {
    /**
     * Get coins
     *
     * @return string[]
     */
    public function getCoins();
    /**
     * get coin name
     *
     * @param string $coin
     * @return string
     */
    public function getNameForCoin($coin);
    /**
     * get network info for coin
     *
     * @param string $coin
     * @return ExchangeCoinNetworkInfo[]
     */
    public function getCoinNetworksForCoin($coin);
    /**
     * get network info for coin and network
     *
     * @param string $coin
     * @param string $network
     * @return ExchangeCoinNetworkInfo
     */
    public function getNetwork($coin, $network);
    /**
     * Undocumented function
     *
     * @param string $coin
     * @return string[]
     */
    public function getNetworkCodesForCoin($coin);
    /**
     * get balance free
     *
     * @param string $coin
     * @return string
     */
    public function getBalanceFree ($coin);
    /**
     * Undocumented function
     *
     * @param string $coin
     * @return string
     */
    public function getBalanceLocked ($coin);

}