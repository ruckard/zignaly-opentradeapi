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

use Zignaly\exchange\ExchangeDustTransfer;

/*
{
    "totalServiceCharge":"0.02102542",
    "totalTransfered":"1.05127099",
    "transferResult":[
        {
            "amount":"0.03000000",
            "fromAsset":"ETH",
            "operateTime":1563368549307,
            "serviceChargeAmount":"0.00500000",
            "tranId":2970932918,
            "transferedAmount":"0.25000000"
        },
        {
            "amount":"0.09000000",
            "fromAsset":"LTC",
            "operateTime":1563368549404,
            "serviceChargeAmount":"0.01548000",
            "tranId":2970932918,
            "transferedAmount":"0.77400000"
        },
        {
            "amount":"248.61878453",
            "fromAsset":"TRX",
            "operateTime":1563368549489,
            "serviceChargeAmount":"0.00054542",
            "tranId":2970932918,
            "transferedAmount":"0.02727099"
        }
    ]
}
*/

/**
 * Dust transfer
 * 
 */
class ExchangeDustTransferCcxt implements ExchangeDustTransfer {
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
     * 
     *
     * @return string
     */
    public function getTotalServiceCharge(){
        return $this->get("totalServiceCharge");
    }
    /**
     * 
     *
     * @return string
     */
    public function getTotalTransfered(){
        return $this->get("totalTransfered");
    }
    /**
     * Undocumented function
     *
     * @return ExchangeDustTransferUnit[]
     */
    public function getTrasnferUnits(){
        $units = $this->get("transferResult");
        if ($units == null) return array();
        $ret = array();
        foreach($units as $unit){
            $ret[] = new ExchangeDustTransferUnitCcxt($unit);
        }
        return $ret;
    }
}