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

use Zignaly\exchange\ExchangeDustTransferUnit;

/*
    {
        "amount":"0.03000000",
        "fromAsset":"ETH",
        "operateTime":1563368549307,
        "serviceChargeAmount":"0.00500000",
        "tranId":2970932918,
        "transferedAmount":"0.25000000"
    }
*/
class ExchangeDustTransferUnitCcxt implements ExchangeDustTransferUnit{
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
     * amount
     *
     * @return string
     */
    public function getAmount(){
        return $this->get("amount");
    }
    /**
     * asset
     *
     * @return string
     */
    public function getFromAsset(){
        return $this->get("fromAsset");
    }
    /**
     * serviceChargeAmount
     *
     * @return string
     */
    public function getServiceChargeAmount(){
        return $this->get("serviceChargeAmount");
    }
    /**
     * tranId
     *
     * @return string
     */
    public function getTranId(){
        return strval($this->get("tranId"));
    }
    /**
     * transferedAmount
     *
     * @return string
     */
    public function getTransferedAmount(){
        return strval($this->get("transferedAmount"));
    }
}