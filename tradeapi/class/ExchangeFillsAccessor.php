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

use Zignaly\exchange\ExchangeTrade;

class ExchangeFillsAccessor implements ArrayAccess {
    /** @var ExchangeTrade */
    private $exchangeTrade;
    public function __construct(ExchangeTrade $exchangeTrade) {
        $this->exchangeTrade = $exchangeTrade;
    }

    public function offsetSet($offset, $valor) {
        throw new Exception ("Could not set in this object");
    }

    public function offsetExists($offset) {
        try {
            return $this->offsetGet($offset) != null;
        } catch (Exception $ex){
            return false;
        }
    }

    public function offsetUnset($offset) {
        throw new Exception ("Could not unset from this object");
    }

    public function offsetGet($offset) {
        switch ($offset){
            case "price":
                return strval($this->exchangeTrade->getPrice ());
            case "qty":
                return strval($this->exchangeTrade->getAmount ());
            case "commission":
                return strval($this->exchangeTrade->getFeeCost ());
            case "commissionAsset":
                return $this->exchangeTrade->getFeeCurrency();
            default:
                return null;
        }
    }
}

/* BINANCE PHP API TRADE INFO
    {
      "price": "4000.00000000",
      "qty": "1.00000000",
      "commission": "4.00000000",
      "commissionAsset": "USDT"
    }
*/