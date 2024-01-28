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
use Zignaly\exchange\marketencoding\BinancefuturesMarketEncoder;
use Zignaly\exchange\marketencoding\BinanceMarketEncoder;
use Zignaly\exchange\marketencoding\MarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\ExchangeMediator\ExchangeMediator;
use Zignaly\Mediator\PositionHandler\BitmexPositionHandler;

/**
 * Exchange mediator mongo db exchange record for Bitmex exchange
 */
class ExchangeHandler
{
    /**
     * Market encoder
     *
     * @var MarketEncoder
     */
    protected $marketEncoder;

    /**
     * Constructor
     *
     * @param string $exchangeName
     * @param string $exchangeType
     */
    public function __construct($exchangeName, $exchangeType)
    {
        $this->marketEncoder = BaseMarketEncoder::newInstance($exchangeName, $exchangeType);
    }

    /**
     * Create ExchangeHandler for name and type
     *
     * @param string $exchangeName exchange name
     * @param string $exchangeType exchange type
     * 
     * @return ExchangeHandler
     */
    public static function newInstance($exchangeName, $exchangeType)
    {
        if (ZignalyExchangeCodes::isBitmex($exchangeName)) {
            return new BitmexExchangeHandler();
        } else {
            return new ExchangeHandler($exchangeName, $exchangeType);
        }
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
        return $amount * $price;
    }
    /**
     * Calc order cost for zig id (pair)
     *
     * @param string $zigid zignaly id (pair)
     * @param float $amount 
     * @param float $price
     * 
     * @return float
     */
    public function calculateOrderCostZignalyPair($zigid, $amount, $price)
    {
        return $amount * $price;
    }
    /**
     * Calc order cost for ccxt symbol)
     *
     * @param string $symbol
     * @param float $amount 
     * @param float $price
     * 
     * @return float
     */
    public function calculateOrderCostCcxtSymbol($symbol, $amount, $price)
    {
        return $this->calculateOrderCostZignalyPair($symbol, $amount, $price);
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
        return $positionSize / $price;
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
        $market = $this->marketEncoder->getMarket($zigid);
        if (null === $market) {
            return null;
        }
        $marketArray = $market->asArray();
        if (!isset($marketArray['limits'])) {
            return null;
        }

        return $marketArray['limits'];
    }

    /**
     * Check if value is good
     *
     * @param string $limit amount|price|cost
     * @param string $type  min|max
     * @param float  $value value to check
     * @param string $zigid zignaly id (pair)
     * 
     * @return void
     */
    public function checkIfValueIsGood($limit, $type, $value, $zigid)
    {

        $limits = $this->getMarketLimitsArray($zigid);
        if (null === $limits) {
            return true; //We were returning false, but that was a really bad practice.
        }

        if (!isset($limits[$limit])) {
            return true;
        }

        if (!isset($limits[$limit][$type]) || $limits[$limit][$type] == '') {
            return true;
        }

        if ($value == 0) {
            return false;
        }

        if ($type == 'max') {
            return $value <= $limits[$limit][$type];
        } else {
            return $value >= $limits[$limit][$type];
        }
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
        return $cost / $amount;
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
        if ($isShort) {
            $grossProfit = ($entryAvgPrice - $exitAvgPrice) * $exitTotalQty - ($entryTotalQty - $exitTotalQty) * $entryAvgPrice;
        } else {
            $grossProfit = ($exitAvgPrice - $entryAvgPrice) * $exitTotalQty - ($entryTotalQty - $exitTotalQty) * $entryAvgPrice;
        }
        return $grossProfit;
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
        if (!$isShort) {
            $currentGrossProfits = ($currentPrice - $avgEntryPrice) * $remainingAmount;
        } else {
            $currentGrossProfits = ($avgEntryPrice - $currentPrice) * $remainingAmount;
        }
        
        return $currentGrossProfits;
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
        if (!$isShort) {
            return $exitAvgPrice * $exitAmount - $entryAvgPrice * $entryAmount;
        } else {
            return $entryAvgPrice * $entryAmount - $exitAvgPrice * $exitAmount; 
        }
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
        $profitAndLosses = $remainingAmount * ($currentExitPrice - $avgEntryPrice) + $exitAmount * ($avgExitPrice - $avgEntryPrice);
        return $isShort ? $profitAndLosses * -1 : $profitAndLosses;
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
        return $amount * $price;
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
        if ($commissionAsset == $quote) {
            return $commission;
        } else {
            return $commission * $price;
        }
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
        return $incomeEntry->getIncome();
    }

    /**
     * @return string
     */
    public function getDefaultMarginMode(): string
    {
        return 'cross';
    }

    /**
     * Because the total amount could be above the maximum limit, we return an array of amounts to do an order per item.
     * @param float $amountToReduce
     * @param string $zignalyId
     * @return array
     */
    public function getMaxAmountsForMarketOrders(
        float $amountToReduce,
        string $zignalyId,
        $monolog = null
    ) {
        if (!($this->marketEncoder instanceof BinanceMarketEncoder ||
            $this->marketEncoder instanceof BinancefuturesMarketEncoder
        )) {
            // Hack to check what is not working in this method
            // remove monolog when done
            if ($monolog!=null) $monolog->sendEntry('debug', "getMaxmountsForMarketOrders ".$this->marketEncoder->getExchangeName());
            // throw new \Exception('This method only valid for Binance exchange');
            return [$amountToReduce];
        }

        $amounts = [];
        
        if (!$this->checkIfValueIsGood('market', 'max', $amountToReduce, $zignalyId)) {
            $market = $this->marketEncoder->getMarket($zignalyId);
            $limits = $market->getLimitsMarket();
            $exchangeType = $this->marketEncoder->getExchangeName();
            if ($monolog!=null) $monolog->sendEntry('debug', "limits for market {$exchangeType} ".$zignalyId, $this->getMarketLimitsArray($zignalyId));
            if (null === $limits) {
                $amounts[] = $amountToReduce;
            } else {
                $maxAmount = $limits->getMax();
                $totalAmountToReduce = $amountToReduce;
                while ($totalAmountToReduce > 0) {
                    $amount = $totalAmountToReduce > $maxAmount ? $maxAmount : $totalAmountToReduce;
                    $amounts[] = $amount;
                    $totalAmountToReduce -= $amount;
                }
            }
        } else {
            $exchangeType = $this->marketEncoder->getExchangeName();
            if ($monolog!=null) $monolog->sendEntry('debug', "limits for market {$exchangeType} " . $zignalyId, $this->getMarketLimitsArray($zignalyId));
            if ($monolog!=null) $monolog->sendEntry('debug', "amountToReduce ".$amountToReduce);
            $amounts[] = $amountToReduce;
        }

        return $amounts;
    }
}
