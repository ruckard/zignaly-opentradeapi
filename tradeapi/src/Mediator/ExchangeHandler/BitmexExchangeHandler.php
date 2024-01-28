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


namespace Zignaly\Mediator\ExchangeHandler;

use Zignaly\exchange\ExchangeIncomeType;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\marketencoding\BitmexMarketEncoder;
use Zignaly\exchange\marketencoding\MarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\PositionHandler\BitmexPositionHandler;
use Zignaly\Process\DIContainer;

/**
 * Exchange mediator mongo db exchange record for Bitmex exchange
 */
class BitmexExchangeHandler extends ExchangeHandler
{
    /**
     * @var \Zignaly\redis\ZignalyLastPriceRedisService
     */
    protected $lastPrices;

    public function __construct()
    {
        parent::__construct(
            ZignalyExchangeCodes::ZignalyBitmex,
            "futures"
        );
        $container = DIContainer::getContainer();
        $this->lastPrices = $container->get('lastPrice');
    }
    /**
     * Calc position size for zig id (pair)
     *
     * @param string $zigid zignaly id (pair)
     * @param float $amount
     * @param float $price
     *
     * @return float
     */
    public function calculatePositionSize($zigid, $amount, $price)
    {
        $market = $this->marketEncoder->getMarket($zigid);
        $multiplier = $market->getMultiplier();
        $power = $market->isInverse() ? -1 : 1;
        return 0.0 === (float) $price ? 0.0: ($price ** $power) * $amount * $multiplier;
    }
    /**
     * Calc order cost for zig id (pair)
     *
     * @param string $zigid zignaly id (pair)
     * @param float $amount 
     * @param float $price
     * 
     * @return void
     */
    public function calculateOrderCostZignalyPair($zigid, $amount, $price)
    {
        $market = $this->marketEncoder->getMarket($zigid);
        $multiplier = $market->getMultiplier();
        $power = $market->isInverse() ? -1 : 1;
        return pow($price, $power) * $amount * $multiplier;
    }
    /**
     * Calc order cost for ccxt symbol)
     *
     * @param string $symbol
     * @param float $amount 
     * @param float $price
     * 
     * @return void
     */
    public function calculateOrderCostCcxtSymbol($symbol, $amount, $price)
    {
        $zigId = $this->marketEncoder->fromCcxt($symbol);
        return $this->calculateOrderCostZignalyPair($zigId, $amount, $price);
    }
    /**
     * Calc amount from position size
     *
     * @param string $zigid
     * @param float $positionSize
     * @param float $price
     * 
     * @return float
     */
    public function calculateAmountFromPositionSize($zigid, $positionSize, $price)
    {
        $market = $this->marketEncoder->getMarket($zigid);
        $multiplier = $market->getMultiplier();
        $power = $market->isInverse() ? 1 : -1;
        return pow($price, $power) * $positionSize / $multiplier;
    }    

    /**
     * Get market limits
     *
     * @param string $zigid zignaly id (pair)
     * 
     * @return array
     */
    public function getMarketLimitsArray($zigid)
    {
        $limits = parent::getMarketLimitsArray($zigid);
        if (null === $limits) {
            return $limits;
        }
        
        $market = $this->marketEncoder->getMarket($zigid);
        if ($market->isInverse()) {
            $newLimits = $limits;
            $newLimits['amount1'] = $newLimits['cost'];
            $newLimits['cost'] = $newLimits['amount'];
            $newLimits['amount'] = $newLimits['amount1'];
            unset($newLimits['amount1']);
            return $newLimits;
        }

        return $limits;
    }
    /**
     * Calculate price from cost and amount
     *
     * @param string $zigid
     * @param float $cost
     * @param float $amount
     * 
     * @return float
     */
    public function calculatePriceFromCostAmount($zigid, $cost, $amount)
    {
        $market = $this->marketEncoder->getMarket($zigid);
        $multiplier = $market->getMultiplier();
        $power = $market->isInverse() ? -1 : 1;
        return pow($cost / ($amount * $multiplier), $power);
    }
    /**
     * Calculate gross profit
     *
     * @param symbol $zigid
     * @param bool $isShort
     * @param float $entryAvgPrice
     * @param float $exitAvgPrice
     * @param float $entryTotalQty
     * @param float $exitTotalQty
     * 
     * @return float
     */
    public function calculateGrossProfit($zigid, $isShort, $entryAvgPrice, $exitAvgPrice, $entryTotalQty, $exitTotalQty)
    {
        $market = $this->marketEncoder->getMarket($zigid);
        $multiplier = $market->getMultiplier();
        $power = $market->isInverse() ? -1 : 1;
        
        $preprocesedEntryAvgPrice = pow($entryAvgPrice, $power);
        if (is_infinite($preprocesedEntryAvgPrice)) {
            $preprocesedEntryAvgPrice = 0;
        }
        $preprocesedExitAvgPrice = pow($exitAvgPrice, $power);
        if (is_infinite($preprocesedExitAvgPrice)) {
            $preprocesedExitAvgPrice = 0;
        }
        if ($isShort) {
            $grossProfit = ( $preprocesedEntryAvgPrice - $preprocesedExitAvgPrice) * $exitTotalQty
                - ($entryTotalQty - $exitTotalQty) * $preprocesedEntryAvgPrice;
        } else {
            $grossProfit = ($preprocesedExitAvgPrice - $preprocesedEntryAvgPrice) * $exitTotalQty
                - ($entryTotalQty - $exitTotalQty) * $preprocesedEntryAvgPrice;
        }
        return $grossProfit * $power * $multiplier;
    }

    /**
     * Calculate current gross profit
     *
     * @param string $zigid           zignaly id/pair
     * @param bool   $isShort         is short position
     * @param float  $avgEntryPrice   avg entry price
     * @param float  $currentPrice    current price
     * @param float  $remainingAmount remaining amount
     * 
     * @return float
     */
    public function calculateCurrentGrossProfit($zigid, $isShort, $avgEntryPrice, $currentPrice, $remainingAmount)
    {
        $market = $this->marketEncoder->getMarket($zigid);
        $multiplier = $market->getMultiplier();
        $power = $market->isInverse() ? -1 : 1;
        $preprocesedEntryAvgPrice = pow($avgEntryPrice, $power);
        if (is_infinite($preprocesedEntryAvgPrice)) {
            $preprocesedEntryAvgPrice = 0;
        }
        $preprocesedCurrentPrice = pow($currentPrice, $power);
        if (is_infinite($preprocesedCurrentPrice)) {
            $preprocesedCurrentPrice = 0;
        }
        if (!$isShort) {
            $currentGrossProfits = ($preprocesedCurrentPrice - $preprocesedEntryAvgPrice) * $remainingAmount;
        } else {
            $currentGrossProfits = ($preprocesedEntryAvgPrice - $preprocesedCurrentPrice) * $remainingAmount;
        }
        
        return $currentGrossProfits * $power * $multiplier;
    }
     /**
     *  Calculate gross profit2
     *
     * @param string $zigid
     * @param bool   $isShort
     * @param float  $exitAvgPrice
     * @param float  $exitAmount
     * @param float  $entryAvgPrice
     * @param float  $entryAmount
     * @return float
     */
    public function calculateGrossProfit2($zigid, $isShort, $exitAvgPrice, $exitAmount, $entryAvgPrice, $entryAmount)
    {
        $market = $this->marketEncoder->getMarket($zigid);
        $power = $market->isInverse() ? -1 : 1;
        
        $preprocesedEntryAvgPrice = pow($entryAvgPrice, $power);
        if (is_infinite($preprocesedEntryAvgPrice)) {
            $preprocesedEntryAvgPrice = 0;
        }
        $preprocesedExitAvgPrice = pow($exitAvgPrice, $power);
        if (is_infinite($preprocesedExitAvgPrice)) {
            $preprocesedExitAvgPrice = 0;
        }
        if (!$isShort) {
            $profit = $preprocesedExitAvgPrice * $exitAmount - $preprocesedEntryAvgPrice * $entryAmount;
        } else {
            $profit = $preprocesedEntryAvgPrice * $entryAmount - $preprocesedExitAvgPrice * $exitAmount; 
        }

        return $profit * $power;
    }
    /**
     * Calculate profit and losess
     *
     * @param string $zigid
     * @param bool   $isShort
     * @param float  $remainingAmount
     * @param float  $currentExitPrice
     * @param float  $avgEntryPrice
     * @param float  $avgExitPrice
     * @param float  $exitAmount
     * 
     * @return float
     */
    public function calculateProfitAndLossed($zigid, $isShort, $remainingAmount, $currentExitPrice, $avgEntryPrice, $avgExitPrice, $exitAmount)
    {
        $market = $this->marketEncoder->getMarket($zigid);
        $power = $market->isInverse() ? -1 : 1;
        
        $preprocesedCurrentExitPrice = pow($currentExitPrice, $power);
        if (is_infinite($preprocesedCurrentExitPrice)) {
            $preprocesedCurrentExitPrice = 0;
        }
        $preprocesedEntryAvgPrice = pow($avgEntryPrice, $power);
        if (is_infinite($preprocesedEntryAvgPrice)) {
            $preprocesedEntryAvgPrice = 0;
        }
        $preprocesedExitAvgPrice = pow($avgExitPrice, $power);
        if (is_infinite($preprocesedExitAvgPrice)) {
            $preprocesedExitAvgPrice = 0;
        }
        //$profitAndLosses = $remainingAmount * ($currentExitPrice - $avgEntryPrice) + $exitAmount * ($avgExitPrice - $avgEntryPrice);
        $profitAndLosses = $remainingAmount * ($preprocesedCurrentExitPrice - $preprocesedEntryAvgPrice) + $exitAmount * ($preprocesedExitAvgPrice - $preprocesedEntryAvgPrice);
        return $isShort ? $profitAndLosses * -1 * $power : $profitAndLosses * $power;
    }
    /**
     * Get funding fee tag for this exchange
     *
     * @return string
     */
    public function getFundingFeesTag()
    {
        return ExchangeIncomeType::FundinfFee;
    }
    /**
     * Calculate real investment for position size without leverage
     *
     * @param string $zigid
     * @param float  $positionSize
     * 
     * @return float
     */
    public function calculateRealInvestmentFromPositionSize($zigid, $positionSize)
    {
        //$market = $this->marketEncoder->getMarket($zigid);
        //$multiplier = $market->getMultiplier();
        
        // return $positionSize * $multiplier;
        return $positionSize;
    }
    /**
     * Calc real investment
     *
     * @param string $zigid
     * @param float $positionSize
     * @param float $price
     * 
     * @return float
     */
    public function calculateRealInvestment($zigid, $amount, $price)
    {
        $market = $this->marketEncoder->getMarket($zigid);
        $multiplier = $market->getMultiplier();
        $power = $market->isInverse() ? -1 : 1;
        return pow($price, $power) * $amount * $multiplier;
    }
    /**
     * Calculate trade commission
     *
     * @param string $zigid
     * @param string $commissionAsset
     * @param float  $commission
     * @param float  $price
     * @param string $quote
     * 
     * @return void
     */
    public function calculateTradeCommission($zigid, $commissionAsset,$commission, $price, $quote)
    {
        return $commission;
    }

    /**
     * Calculate funding fee
     *
     * @param string $zigid
     * @param boolean $isShort
     * @param ExchangeIncome $income
     * 
     * @return float
     */
    public function calculateFundingFeeForExchangeIncome($zigid, $isShort, $incomeEntry)
    {
        $factor = $isShort ? 1 : -1;
        return $incomeEntry->getIncome() * $factor;
    }

    /**
     * @return string
     */
    public function getDefaultMarginMode(): string
    {
        return 'isolated';
    }
}
