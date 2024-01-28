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

namespace Zignaly\exchange\ccxtwrap\exchanges;

use Zignaly\exchange\ccxtwrap\BaseExchangeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeTradeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeTradeFeeCcxt;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ExchangeTrade;
use Zignaly\exchange\ZignalyExchangeCodes;

class Bittrex extends BaseExchangeCcxt {
    public function __construct (ExchangeOptions $options) {
        parent::__construct ("bittrex", $options);
    }
    /**
     * get exchange if (zignaly internal code)
     *
     * @return void
     */
    public function getId(){
        return ZignalyExchangeCodes::ZignalyBittrex;
    }

    /**
     * order info
     *
     * @param ExchangeOrder $order
     * @return ExchangeOrder
     */
    public function orderInfo (string $orderId, string $symbol = null) {
        try {
            $ccxtOrder = $this->exchange->fetchOrder ($orderId, $symbol);
            $exchangeOrder = new ExchangeOrderCcxt ($ccxtOrder);
            if (($exchangeOrder->getStatus() == ExchangeOrderStatus::Closed)
                || ($exchangeOrder->getStatus() == ExchangeOrderStatus::Canceled && $exchangeOrder->getFilled() > 0)
                || ($exchangeOrder->getStatus() == ExchangeOrderStatus::Expired && $exchangeOrder->getFilled() > 0)
            ) {
                $trades = array(
                    new ExchangeTradeCcxt ($this->exchange->orders_to_trades ([$ccxtOrder]))
                );
                $exchangeOrder->setTrades ($trades);
            }
            return $exchangeOrder;
        } catch (\ccxt\BaseError $ex) {
            throw $this->parseCcxtException ($ex, $ex->getMessage());
        }
    }
    
    /**
     * Calculate fee for order
     *
     * @param ExchangeTrade $order
     * @return ExchangeTradeFee
     */
    public function calculateFeeForTrade(ExchangeTrade $trade){
        $symbol = $trade->getSymbol();
        $market = $this->exchange->market($symbol);
        $quote = $market['quote'];
        $feeCost = $trade->getCost() * 0.001;// 0.0005;

        return new ExchangeTradeFeeCcxt($feeCost, $quote, null);
    }

    /**
     * @inheritDoc
     */
    public function withdrawCurrencyNetworkPrecision(string $currencyCode, string $network, float $amount)
    {
        return parent::withdrawCurrencyNetworkPrecision($currencyCode, $network, $amount);
    }
}