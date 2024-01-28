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


namespace Zignaly\Entity\Query;

/**
 * Provide a query methods to consume exchange balance data object.
 *
 * @package Zignaly\Entity\Query
 */
class UserExchangeBalanceQuery {

    /**
     * Raw user exchange balance.
     *
     * @var array
     */
    private $balance;

    public function __construct(array $userExchangeBalance)
   {
       $this->balance = $userExchangeBalance;
   }

    /**
     * Get list of user exchange currencies balance.
     *
     * @param bool $filterEmpty Flag to filter currencies without balance.
     *
     * @return array Associative array of currency => balance.
     */
    public function getFreeByCurrency($filterEmpty = true)
    {
        if (!isset($this->balance['free'])) {
            return [];
        }

        if (!$filterEmpty) {
            return $this->balance['free'];
        }

        // Filter currencies with empty balance.
        $freeCurrenciesBalance = $this->balance['free'];
        foreach ($freeCurrenciesBalance as $currencyCode => $freeCurrencyBalance) {
            if ($freeCurrencyBalance <= 0) {
                unset($freeCurrenciesBalance[$currencyCode]);
            }
        }

        return $freeCurrenciesBalance;
    }

    /**
     * Get list of user exchange currencies balance.
     *
     * @return array Associative array of currency => balance.
     */
    public function getMaxWithdrawAmountByCurrency(): array
    {
        $maxWithdrawAmount = $this->balance['max_withdraw_amount'] ?? null;
        if (empty($maxWithdrawAmount)) {
            //Fallback to the free
            return $this->getFreeByCurrency();
        }

        // Filter currencies with empty balance.
        foreach ($maxWithdrawAmount as $currencyCode => $amount) {
            if ($amount <= 0) {
                unset($maxWithdrawAmount[$currencyCode]);
            }
        }

        return $maxWithdrawAmount;
    }

    /**
     * Get balance exchange account type.
     *
     * @return string
     */
    public function getAccountType()
    {
        $accountType = 'UNDEFINED';
        if (isset($this->balance['info']['accountType'])) {
            $accountType = $this->balance['info']['accountType'];
        }

        return $accountType;
    }

}