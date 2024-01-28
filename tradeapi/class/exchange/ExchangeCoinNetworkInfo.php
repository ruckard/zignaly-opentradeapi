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

interface ExchangeCoinNetworkInfo
{
    /**
     * get address regex
     *
     * @return string
     */
    public function getAddressRegex();

    /**
     * get coin
     *
     * @return string
     */
    public function getCoin();

    /**
     * get deposit description
     *
     * @return string
     */
    public function getDepositDesc();

    /**
     * is deposit enabled
     *
     * @return boolean
     */
    public function isDepositEnabled();

    /**
     * is default network
     *
     * @return boolean
     */
    public function isDefault();

    /**
     * get memo regex
     *
     * @return string
     */
    public function getMemoRegEx();

    /**
     * Get reset address status
     *
     * @return bool
     */
    public function isResetAddressStatus();

    /**
     * get network name
     *
     * @return string
     */
    public function getName();

    /**
     * get network
     *
     * @return string
     */
    public function getNetwork();

    /**
     * special tips
     *
     * @return string
     */
    public function getSpecialTips();

    /**
     * get withdraw desc
     *
     * @return string
     */
    public function getWithdrawDesc();

    /**
     * is withdraw enabled
     *
     * @return boolean
     */
    public function isWithdrawEnabled();

    /**
     * withdraw fee
     *
     * @return string
     */
    public function getWithdrawFee();

    /**
     * withdraw min
     *
     * @return string
     */
    public function getWithdrawMin();

    /**
     * Withdraw network precision.
     *
     * @see https://www.reddit.com/r/BinanceExchange/comments/995jra/getting_atomic_withdraw_unit_from_api
     * @return string
     */
    public function getWithdrawIntegerMultiple();
}