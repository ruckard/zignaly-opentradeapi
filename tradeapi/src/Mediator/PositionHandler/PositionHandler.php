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

use MongoDB\BSON\ObjectId;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\marketencoding\MarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Process\DIContainer;
use Zignaly\service\ZignalyLastPriceService;

class PositionHandler
{
    /**
     *  @var \MongoDB\Databas
     */
    protected $mongoDBLink;
    /**
     * Exchange name
     *
     * @var string
     */
    protected $exchangeName;
    /**
     * Exchange type
     *
     * @var string
     */
    protected $exchangeType;
    protected $container;
    /**
     * Last price service
     *
     * @var ZignalyLastPriceService
     */
    protected $lastPriceService;
    /**
     * Market encoder
     *
     * @var MarketEncoder
     */
    protected $marketEncoder;

    /**
     * Constructor
     */
    public function __construct($exchangeName, $exchangeType = "spot")
    {
        global $mongoDBLink;

        $this->container = DIContainer::getContainer();
        $this->mongoDBLink = $mongoDBLink;
        $this->exchangeName = $exchangeName;
        $this->exchangeType = $exchangeType;
        $this->lastPriceService = $this->container->get('lastPrice');
        $this->marketEncoder = BaseMarketEncoder::newInstance(
            $exchangeName,
            $exchangeType
        );
    }
    
    /**
     * Create position handler for exchange
     *
     * @param string $exchangeName
     * 
     * @return PositionHandler
     */
    public static function fromExchangeName($exchangeName, $exchangeType)
    {
        if (ZignalyExchangeCodes::isBitmex($exchangeName)) {
            return new BitmexPositionHandler();
        }
        return new PositionHandler($exchangeName, $exchangeType);
    }
    /**
     * Get open positions for compute position size
     *
     * @param ObjectId $userId             user id
     * @param string   $quote              quote asset
     * @param string   $internalExchangeId internal exchange id
     * 
     * @return object[]
     */
    public function getOpenPositionsForComputePositionSize($userId, $quote, $internalExchangeId)
    {
        $find = [
            'closed' => false,
            'user._id' => $userId,
            'exchange.internalId' => $internalExchangeId,
            'signal.quote' => $quote
        ];

        return $this->mongoDBLink->selectCollection('position')->find($find);
    }

    /**
     * Get remaining amount from position trades
     *
     * @param object[] $trades trade list
     * @param string   $base   base asset
     * 
     * @return float
     */
    public function getRemainingAmount($trades, $base)
    {
        $remainingAmount = 0;
        
        // avoid to substract the BNB commission when binance futures and BNB position
        $isBinanceExchange = ZignalyExchangeCodes::isBinance(
            ZignalyExchangeCodes::getRealExchangeName($this->exchangeName)
        );

        $isFutures = 'futures' === strtolower($this->exchangeType);
        $avoidSubstractCommision = ($isBinanceExchange && $isFutures && ('BNB' == $base));
        foreach ($trades as $trade) {
            if ($trade->isBuyer) {
                $commission = (($trade->commissionAsset == $base)
                    && !$avoidSubstractCommision) ? $trade->commission : 0;
                $amount = $trade->qty - $commission;
                $remainingAmount += $amount;
            } else {
                $remainingAmount -= $trade->qty;
            }
        }

        return $remainingAmount;
    }
    /**
     * Compute invested value for amount and leverage for symbol
     * at current price
     *
     * @param float $amount
     * @param int $leverage
     * @param string $symbol
     * 
     * @return float
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
     * Compute invested value for amount, price and leverage for symbol
     *
     * @param float $amount
     * @param int $leverage
     * @param string $symbol
     * 
     * @return float
     */
    public function computeInvestedValueForPrice($amount, $currentPrice, $leverage, $symbol)
    {
        $quoteAmount = $currentPrice * $amount;
        return $leverage > 0? $quoteAmount / $leverage : $quoteAmount;
    }
}