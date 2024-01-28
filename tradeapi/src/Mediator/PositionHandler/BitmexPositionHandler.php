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

namespace Zignaly\Mediator\PositionHandler;

use Zignaly\exchange\ZignalyExchangeCodes;

class BitmexPositionHandler extends PositionHandler
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct(
            ZignalyExchangeCodes::ZignalyBitmex,
            "futures"
        );
    }

    /**
     * @inheritDoc
     */
    public function getOpenPositionsForComputePositionSize($userId, $quote, $internalExchangeId)
    {
        $find = [
            'closed' => false,
            'user._id' => $userId,
            'exchange.internalId' => $internalExchangeId,
            // 'signal.quote' => $quote
        ];

        return $this->mongoDBLink->selectCollection('position')->find($find);
    }

    /**
     * @inheritDoc
     */
    public function getRemainingAmount($trades, $base)
    {
        $remainingAmount = 0;
        foreach ($trades as $trade) {
            if ($trade->isBuyer) {
                // $commission = $trade->commissionAsset == $base ? $trade->commission : 0;
                // all trades commission would be in XBT
                $commission = $trade->commission;
                $amount = $trade->qty - $commission;
                $remainingAmount += $amount;
            } else {
                $remainingAmount -= $trade->qty;
            }
        }

        return $remainingAmount;
    }
    /**
     * @inheritDoc
     */
    public function computeInvestedValue($amount, $leverage, $symbol)
    {
        $currentPrice = $this->lastPriceService->lastPriceStrForSymbol($this->exchangeName, $symbol);
                
        if (!$currentPrice) {
            $currentPrice = 0;
        }

        return $this->computeInvestedValueForPrice($amount, $currentPrice, $leverage, $symbol);
    }
    /**
     * @inheritDoc
     */
    public function computeInvestedValueForPrice($amount, $currentPrice, $leverage, $symbol)
    {
        $multiplier = $this->marketEncoder->getMultiplier($symbol);
        if ($this->marketEncoder->isInverse($symbol)) {
            $quoteAmount = $amount / $currentPrice;
        } else {
            $quoteAmount = $amount * $currentPrice;
        }
        
        return ($quoteAmount * $multiplier) / ($leverage > 0? $leverage : 1);
    }
}