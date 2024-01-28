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
use Zignaly\exchange\ExchangeUserTransactionInfo;

/**
 * Exchange ccxt user transactioninfo
 *  [
 *       {
 *           "coin": "BTC",
 *           "depositAllEnable": True,
 *           "free": "0.08074558",
 *           "freeze": "0.00000000",
 *           "ipoable": "0.00000000",
 *           "ipoing": "0.00000000",
 *           "isLegalMoney": False,
 *           "locked": "0.00000000",
 *           "name": "Bitcoin",
 *           "networkList": [
 *               {
 *                   "addressRegex": "^(bnb1)[0-9a-z]{38}$",
 *                   "coin": "BTC",
 *                   "depositDesc": "Wallet Maintenance, Deposit Suspended",
 *                   "depositEnable": True,
 *                   "isDefault": False,        
 *                   "memoRegex": "^[0-9A-Za-z\\-_]{1,120}$",
 *                   "name": "BEP2",
 *                   "network": "BNB",            
 *                   "resetAddressStatus": False,
 *                   "specialTips": "Both a MEMO and an Address are required to successfully deposit your BEP2-BTCB tokens to Binance.",
 *                   "withdrawDesc": "Wallet Maintenance, Withdrawal Suspended",
 *                   "withdrawEnable": True,
 *                   "withdrawFee": "0.00000220",
 *                   "withdrawMin": "0.00000440"
 *               },
 *           }
 *       }
 *   ]
 */
class ExchangeUserTransactionInfoCcxt implements ExchangeUserTransactionInfo {
    protected $ccxtResponse;
    /** @var array */
    protected $coins;
    /** @var array */
    protected $networks;
    public function __construct ($ccxtResponse) {
        $this->ccxtResponse = $ccxtResponse;
        $this->coins = [];
        $this->networks = [];
        foreach($ccxtResponse as $record){
            $this->coins[$record['coin']] = $record;
            $this->networks[$record['coin']] = [];
            foreach($record["networkList"] as $network){
                $this->networks[$record['coin']][$network["network"]] = new ExchangeCoinNetworkInfoCcxt($network);
            }
        }
    }
    public function getCcxtResponse(){
        return $this->ccxtResponse;
    }

    /**
     * Get coins
     *
     * @return string[]
     */
    public function getCoins(){
        return array_keys($this->coins);
    }
    /**
     * get coin name
     *
     * @param string $coin
     * @return string
     */
    public function getNameForCoin($coin){
        if (array_key_exists ($coin, $this->coins)){
            return $this->coins[$coin]['name'];
        }
        return null;
    }
    /**
     * get network info for coin
     *
     * @param string $coin
     * @return ExchangeCoinNetworkInfo[]
     */
    public function getCoinNetworksForCoin($coin){
        if (array_key_exists ($coin, $this->networks)){
            return array_values($this->networks[$coin]);
        }
        return [];
    }

    /**
     * get network info for coin and network
     *
     * @param string $coin
     * @param string $network
     * @return ExchangeCoinNetworkInfo
     */
    public function getNetwork($coin, $network){
        if (array_key_exists ($coin, $this->networks)){
            if (array_key_exists ($network, $this->networks[$coin])){
                return $this->networks[$coin][$network];
            }
        }
        return null;
    }
    /**
     * Undocumented function
     *
     * @param string $coin
     * @return string[]
     */
    public function getNetworkCodesForCoin($coin){
        if (array_key_exists ($coin, $this->networks)){
            return array_keys($this->networks[$coin]);
        }
        return [];
    }

    /**
     * get balance free
     *
     * @param string $coin
     * @return string
     */
    public function getBalanceFree ($coin){
        if (array_key_exists ($coin, $this->coins)){
            return $this->coins[$coin]['free'];
        }
        return null;
    }
    /**
     * Undocumented function
     *
     * @param string $coin
     * @return string
     */
    public function getBalanceLocked ($coin){
        if (array_key_exists ($coin, $this->coins)){
            return $this->coins[$coin]['locked'];
        }
        return null;
    }
    
}