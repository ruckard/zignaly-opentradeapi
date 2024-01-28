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

use Zignaly\exchange\ExchangeCoinNetworkInfo;

/**
 * Exchange ccxt coin network info
 *  {
 *      "addressRegex": "^(bnb1)[0-9a-z]{38}$",
 *      "coin": "BTC",
 *      "depositDesc": "Wallet Maintenance, Deposit Suspended",
 *      "depositEnable": True,
 *      "isDefault": False,
 *      "memoRegex": "^[0-9A-Za-z\\-_]{1,120}$",
 *      "name": "BEP2",
 *      "network": "BNB",
 *      "resetAddressStatus": False,
 *      "specialTips": "Both a MEMO and an Address are required to successfully deposit your BEP2-BTCB tokens to
 * Binance.",
 *      "withdrawDesc": "Wallet Maintenance, Withdrawal Suspended",
 *      "withdrawEnable": True,
 *      "withdrawFee": "0.00000220",
 *      "withdrawMin": "0.00000440"
 *  },
 */
class ExchangeCoinNetworkInfoCcxt implements ExchangeCoinNetworkInfo
{
    protected $ccxtResponse;

    public function __construct($ccxtResponse)
    {
        $this->ccxtResponse = $ccxtResponse;
    }

    public function getCcxtResponse()
    {
        return $this->ccxtResponse;
    }

    private function get($key)
    {
        if (isset($this->ccxtResponse[$key])) {
            return $this->ccxtResponse[$key];
        }

        return null;
    }

    /**
     * get address regex
     *
     * @return string
     */
    public function getAddressRegex()
    {
        return $this->get("addressRegex");
    }

    /**
     * get coin
     *
     * @return string
     */
    public function getCoin()
    {
        return $this->get("coin");
    }

    /**
     * get deposit description
     *
     * @return string
     */
    public function getDepositDesc()
    {
        return $this->get("depositDesc");
    }

    /**
     * is deposit enabled
     *
     * @return boolean
     */
    public function isDepositEnabled()
    {
        return $this->get("depositEnable");
    }

    /**
     * is default network
     *
     * @return boolean
     */
    public function isDefault()
    {
        return $this->get("isDefault");
    }

    /**
     * get memo regex
     *
     * @return string
     */
    public function getMemoRegEx()
    {
        return $this->get("memoRegex");
    }

    /**
     * Get reset address status
     *
     * @return bool
     */
    public function isResetAddressStatus()
    {
        return $this->get("resetAddressStatus");
    }

    /**
     * get network name
     *
     * @return string
     */
    public function getName()
    {
        return $this->get("name");
    }

    /**
     * get network
     *
     * @return string
     */
    public function getNetwork()
    {
        return $this->get("network");
    }

    /**
     * special tips
     *
     * @return string
     */
    public function getSpecialTips()
    {
        return $this->get("specialTips");
    }

    /**
     * get withdraw desc
     *
     * @return string
     */
    public function getWithdrawDesc()
    {
        return $this->get("withdrawDesc");
    }

    /**
     * is withdraw enabled
     *
     * @return boolean
     */
    public function isWithdrawEnabled()
    {
        return $this->get("withdrawEnable");
    }

    /**
     * withdraw fee
     *
     * @return string
     */
    public function getWithdrawFee()
    {
        return $this->get("withdrawFee");
    }

    /**
     * withdraw min
     *
     * @return string
     */
    public function getWithdrawMin()
    {
        return $this->get("withdrawMin");
    }

    /**
     * Withdraw network precision.
     *
     * @see https://www.reddit.com/r/BinanceExchange/comments/995jra/getting_atomic_withdraw_unit_from_api
     * @return string
     */
    public function getWithdrawIntegerMultiple()
    {
        return $this->get("withdrawIntegerMultiple");
    }
}