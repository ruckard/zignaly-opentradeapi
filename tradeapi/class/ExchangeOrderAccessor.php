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


use Zignaly\exchange\ExchangeOrder;
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ExchangeOrderSide;

class ExchangeOrderAccessor implements ArrayAccess
{
    /** @var ExchangeOrder */
    private $exchangeOrder;

    public function __construct(ExchangeOrder $exchangeOrder)
    {
        $this->exchangeOrder = $exchangeOrder;
    }

    public function offsetSet($offset, $valor)
    {
        throw new Exception ("Could not set in this object");
    }

    public function offsetExists($offset)
    {
        try {
            return $this->offsetGet($offset) != null;
        } catch (Exception $ex) {
            return false;
        }
    }

    public function offsetUnset($offset)
    {
        throw new Exception ("Could not unset from this object");
    }

    public function offsetGet($offset)
    {
        switch ($offset) {
            case "symbol":
                return $this->exchangeOrder->getSymbol();
            case "orderId":
                return $this->exchangeOrder->getId();
            case "orderListId":
                throw new Exception ("Invalid offset");
            case "clientOrderId":
                throw new Exception ("Invalid offset");
            case "transactTime":
                return $this->exchangeOrder->getTimestamp();
            case "price":
                return strval($this->exchangeOrder->getPrice());
            case "origQty":
                return strval($this->exchangeOrder->getAmount());
            case "executedQty":
                return strval($this->exchangeOrder->getFilled());
            case "cummulativeQuoteQty":
                throw new Exception ("Invalid offset");
            case "status":
                //Todo: review status correspondence
                switch ($this->exchangeOrder->getStatus()) {
                    case ExchangeOrderStatus::Open:
                        return "NEW";
                    case ExchangeOrderStatus::Closed:
                        return ($this->exchangeOrder->getAmount() === $this->exchangeOrder->getFilled()) ?
                            "FILLED" : "PARTIALLY_FILED";
                    case ExchangeOrderStatus::Canceled:
                        return "CANCELED";
                    default:
                        throw new Exception("Not valid status ".$this->exchangeOrder->getStatus());
                }
            case "timeInForce":
                throw new Exception ("Invalid offset");
            case "type":
                //Todo: review types correspondence
                switch ($this->exchangeOrder->getType()) {
                    case ExchangeOrderType::Market:
                        return "MARKET";
                    case ExchangeOrderType::Limit:
                        return "LIMIT";
                    case ExchangeOrderType::Stop:
                        throw new Exception ("Invalid offset");
                    case ExchangeOrderType::StopLimit:
                        throw new Exception ("Invalid offset");
                    default:
                        throw new Exception("Not valid order type ".$this->exchangeOrder->getType());
                }
            case "side":
                switch ($this->exchangeOrder->getSide()) {
                    case ExchangeOrderSide::Buy:
                        return "BUY";
                    case ExchangeOrderSide::Sell:
                        return "SELL";
                    default:
                        throw new Exception("Not valid order side ".$this->exchangeOrder->getSide());
                }
            case "fills":
                $trades = array();
                foreach ($this->exchangeOrder->getTrades() as $trade) {
                    $trades[] = new ExchangeFillsAccessor($trade);
                }

                return $trades;
            default:
                return null;
        }
    }
}

/* BINANCE PHP API ORDER INFO
{
  "symbol": "BTCUSDT",
  "orderId": 28,
  "orderListId": -1, //Unless OCO, value will be -1
  "clientOrderId": "6gCrw2kRUAF9CvJDGP16IP",
  "transactTime": 1507725176595,
  "price": "1.00000000",
  "origQty": "10.00000000",
  "executedQty": "10.00000000",
  "cummulativeQuoteQty": "10.00000000",
  "status": "FILLED",
  "timeInForce": "GTC",
  "type": "MARKET",
  "side": "SELL",
  "fills": [
    {
      "price": "4000.00000000",
      "qty": "1.00000000",
      "commission": "4.00000000",
      "commissionAsset": "USDT"
    },
    {
      "price": "3999.00000000",
      "qty": "5.00000000",
      "commission": "19.99500000",
      "commissionAsset": "USDT"
    },
    {
      "price": "3998.00000000",
      "qty": "2.00000000",
      "commission": "7.99600000",
      "commissionAsset": "USDT"
    },
    {
      "price": "3997.00000000",
      "qty": "1.00000000",
      "commission": "3.99700000",
      "commissionAsset": "USDT"
    },
    {
      "price": "3995.00000000",
      "qty": "1.00000000",
      "commission": "3.99500000",
      "commissionAsset": "USDT"
    }
  ]
}
*/