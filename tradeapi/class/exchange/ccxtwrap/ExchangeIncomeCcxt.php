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

use Zignaly\exchange\ExchangeIncome;
/**
 * Exchange 
 * (
 *        [info] => Array
 *            (
 *                [symbol] => BZRXUSDT
 *                [incomeType] => COMMISSION
 *                [income] => -194.55820800
 *                [asset] => USDT
 *                [time] => 1599469466000
 *                [info] => 4954106
 *                [tranId] => 10314954106
 *                [tradeId] => 4954106
 *            )
 *
 *        [symbol] => BZRX/USDT
 *        [incomeType] => COMMISSION
 *        [income] => -194.558208
 *        [asset] => USDT
 *        [timestmap] => 1599469466000
 *        [datetime] => 2020-09-07T09:04:26.000Z
 *        [incomeInfo] => 4954106
 *        [tranId] => 10314954106
 *        [tradeId] => 4954106
 *   )

 */
class ExchangeIncomeCcxt implements ExchangeIncome {
    protected $ccxtResponse;
    public function __construct ($ccxtResponse) {
        $this->ccxtResponse = $ccxtResponse;
    }
    public function getCcxtResponse(){
        return $this->ccxtResponse;
    }
    /**
     * get original response
     *
     * @return []
     */
    public function getInfo(){
        return $this->ccxtResponse['info'];
    }
    /**
     * Get symbol
     *
     * @return string
     */
    public function getSymbol(){
        return $this->ccxtResponse['symbol'];
    }
    /**
     * Get income type @see ExchangeIncomeType
     *
     * @return string
     */
    public function getIncomeType(){
        return $this->ccxtResponse['incomeType'];
    }
    /**
     * Get income
     *
     * @return float
     */
    public function getIncome(){
        return $this->ccxtResponse['income'];
    }
    /**
     * Get asset
     *
     * @return string
     */
    public function getAsset(){
        return $this->ccxtResponse['asset'];
    }
    /**
     * Get timestamp
     *
     * @return int
     */
    public function getTimestamp(){
        return $this->ccxtResponse['timestamp'];
    }
    /**
     * Get datetime
     *
     * @return string
     */
    public function getDateTime(){
        return $this->ccxtResponse['datetime'];
    }
    /**
     * Get income info
     *
     * @return int
     */
    public function getIncomeInfo(){
        return $this->ccxtResponse['incomeInfo'];
    }
    /**
     * Get Tran id
     *
     * @return int
     */
    public function getTranId(){
        return $this->ccxtResponse['tranId'];
    }
    /**
     * Get trade id
     *
     * @return int
     */
    public function getTradeId(){
        return $this->ccxtResponse['tradeId'];
    }
}