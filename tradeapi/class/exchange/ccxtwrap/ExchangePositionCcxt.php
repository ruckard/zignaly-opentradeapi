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

use Zignaly\exchange\ExchangePosition;
/**
 
 */

 class ExchangePositionCcxt implements ExchangePosition {

    protected $ccxtResponse;
    public function __construct ($ccxtResponse) {
        $this->ccxtResponse = $ccxtResponse;
    }

    public function getCcxtResponse()
    {
        return $this->ccxtResponse;
    }

    /**
     * Temp function to get any ccxt position field
     * TODO: add methods for each property we need outside
     *
     * @return any
     */
    public function getCcxtField (string $fieldName) {
        return $this->ccxtResponse[$fieldName];
    }

    public function getCcxtDict() {
        return $this->ccxtResponse;
    }

    public function getSymbol()
    {
        return $this->ccxtResponse['symbol'];
    }
    public function getAmount()
    {
        return $this->ccxtResponse['amount'];
    }
    public function getEntryPrice()
    {
        return $this->ccxtResponse['entryprice'];
    }
    public function getMarkPrice()
    {
        return $this->ccxtResponse['markprice'];
    }
    public function getLiquidationPrice()
    {
        return $this->ccxtResponse['liquidationprice'];
    }
    public function getLeverage()
    {
        return $this->ccxtResponse['leverage'];
    }
    public function getMargin()
    {
        return $this->ccxtResponse['margin'];
    }
    public function getSide()
    {
        return $this->ccxtResponse['side'];
    }

     public function isIsolated()
     {
         return $this->ccxtResponse['isolated'] ?? true;
     }
 }

/**
 *   {
 *         "account": 241616,
 *         "symbol": "XBTUSD",
 *         "currency": "XBt",
 *         "underlying": "XBT",
 *         "quoteCurrency": "USD",
 *         "commission": 0.00075,
 *         "initMarginReq": 0.01,
 *         "maintMarginReq": 0.005,
 *         "riskLimit": 20000000000,
 *         "leverage": 100,
 *         "crossMargin": true,
 *         "deleveragePercentile": 1,
 *         "rebalancedPnl": -8,
 *         "prevRealisedPnl": 0,
 *         "prevUnrealisedPnl": 0,
 *         "prevClosePrice": 10581.95,
 *         "openingTimestamp": "2019-09-05T09:00:00.000Z",
 *         "openingQty": 1,
 *         "openingCost": -9620,
 *         "openingComm": -54,
 *         "openOrderBuyQty": 0,
 *         "openOrderBuyCost": 0,
 *         "openOrderBuyPremium": 0,
 *         "openOrderSellQty": 0,
 *         "openOrderSellCost": 0,
 *         "openOrderSellPremium": 0,
 *         "execBuyQty": 0,
 *         "execBuyCost": 0,
 *         "execSellQty": 0,
 *         "execSellCost": 0,
 *         "execQty": 0,
 *         "execCost": 0,
 *         "execComm": 0,
 *         "currentTimestamp": "2019-09-05T09:10:20.359Z",
 *         "currentQty": 1,
 *         "currentCost": -9620,
 *         "currentComm": -54,
 *         "realisedCost": 0,
 *         "unrealisedCost": -9620,
 *         "grossOpenCost": 0,
 *         "grossOpenPremium": 0,
 *         "grossExecCost": 0,
 *         "isOpen": true,
 *         "markPrice": 10601.11,
 *         "markValue": -9433,
 *         "riskValue": 9433,
 *         "homeNotional": 9.433e-5,
 *         "foreignNotional": -1,
 *         "posState": "",
 *         "posCost": -9620,
 *         "posCost2": -9618,
 *         "posCross": 2,
 *         "posInit": 97,
 *         "posComm": 8,
 *         "posLoss": 2,
 *         "posMargin": 105,
 *         "posMaint": 57,
 *         "posAllowance": 0,
 *         "taxableMargin": 0,
 *         "initMargin": 0,
 *         "maintMargin": 292,
 *         "sessionMargin": 0,
 *         "targetExcessMargin": 0,
 *         "varMargin": 0,
 *         "realisedGrossPnl": 0,
 *         "realisedTax": 0,
 *         "realisedPnl": 54,
 *         "unrealisedGrossPnl": 187,
 *         "longBankrupt": 0,
 *         "shortBankrupt": 0,
 *         "taxBase": 187,
 *         "indicativeTaxRate": 0,
 *         "indicativeTax": 0,
 *         "unrealisedTax": 0,
 *         "unrealisedPnl": 187,
 *         "unrealisedPnlPcnt": 0.0194,
 *         "unrealisedRoePcnt": 1.9439,
 *         "simpleQty": null,
 *         "simpleCost": null,
 *         "simpleValue": null,
 *         "simplePnl": null,
 *         "simplePnlPcnt": null,
 *         "avgCostPrice": 10395,
 *         "avgEntryPrice": 10395,
 *         "breakEvenPrice": 10346,
 *         "marginCallPrice": 99.5,
 *         "liquidationPrice": 99.5,
 *         "bankruptPrice": 99.5,
 *         "timestamp": "2019-09-05T09:10:20.359Z",
 *         "lastPrice": 10601.11,
 *         "lastValue": -9433
 *     }
 */