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

use Zignaly\exchange\ExchangeTransaction;
use Zignaly\exchange\ExchangeTransactionType;
use Zignaly\exchange\exceptions;
use Zignaly\exchange\ExchangeTransactionStatus;

/**
 * Exchange ccxt transaction
 * 
 * {
 *   'info': transaction,
 *   'id': id,
 *   'txid': txid,
 *   'timestamp': timestamp,
 *   'datetime': this.iso8601 (timestamp),
 *   'address': address,
 *   'tag': tag,
 *   'type': type,
 *   'amount': amount,
 *   'currency': code,
 *   'status': status,
 *   'updated': undefined,
 *   'fee': undefined,
 * };
 */
class ExchangeTransactionCcxt implements ExchangeTransaction {
    protected $ccxtResponse;
    public function __construct ($ccxtResponse) {
        $this->ccxtResponse = $ccxtResponse;
    }
    public function getCcxtResponse(){
        return $this->ccxtResponse;
    }
    private function get($key){
        if (isset($this->ccxtResponse[$key])) return $this->ccxtResponse[$key];
        return null;
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public function getId(){
        return $this->get("id");
    }

    /**
     * @return mixed|null
     */
    public function getInfo() {
        return $this->get("info");
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public function getTxId(){
        return $this->get("txid");
    }
    /**
     * Undocumented function
     *
     * @return long
     */
    public function getTimestamp(){
        return $this->get("timestamp");
    }
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getDatetime(){
        return $this->get("datetime");
    }
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getAddress(){
        return $this->get("address");
    }
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getTag(){
        return $this->get("tag");
    }
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getType(){
        $type = $this->get("type");
        switch($type){
            case "deposit":
                return ExchangeTransactionType::Deposit;
            case "withdrawal":
                return ExchangeTransactionType::Withdrawal;
            default:
                throw new exceptions\ExchangeInvalidFormatException ("Not valid transaction type from ccxt ".$type);
        }
    }
    /**
     * Undocumented function
     *
     * @return float
     */
    public function getAmount(){
        return $this->get("amount");
    }
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getCurrency(){
        return $this->get("currency");
    }
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getStatus(){
        $status = $this->get("status");
        if ($this->getType() == ExchangeTransactionType::Deposit){
            switch($status){
                case "pending":
                    return ExchangeTransactionStatus::DepositPending;
                case "ok":
                    return ExchangeTransactionStatus::DepositOk;
                default:
                    throw new exceptions\ExchangeInvalidFormatException ("Not valid transaction status from ccxt ".$status);
            }
        } if ($this->getType() == ExchangeTransactionType::Withdrawal){
            switch($status){
                case "pending":
                    return ExchangeTransactionStatus::WithdrawPending;
                case "canceled":
                    return ExchangeTransactionStatus::WithdrawCanceled;
                case "failed":
                    return ExchangeTransactionStatus::WithdrawFailed;
                case "ok":
                    return ExchangeTransactionStatus::WithdrawOk;
                default:
                    throw new exceptions\ExchangeInvalidFormatException ("Not valid transaction status from ccxt ".$status);
            }
        } else {
            throw new exceptions\ExchangeInvalidFormatException ("Unexpected transaction type ".$this->getType());
        }  
    }
    /**
     * Undocumented function
     *
     * @return string
     */
    public function getUpdated(){
        return $this->get("updated");
    }
    /**
     * Undocumented function
     *
     * @return float
     */
    public function getFee(){
        return $this->get("fee");
    }
}