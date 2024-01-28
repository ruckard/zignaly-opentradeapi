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


use Zignaly\Mediator\PositionMediator;
use MongoDB\Model\BSONDocument;
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\PositionCacheMediator;

class Accounting
{
    private $mongoDBLink;

    private $stableCoins = [
        'BKRW',
        'BUSD',
        'DAI',
        'EUR',
        'IDRT',
        'NGN',
        'PAX',
        'RUB',
        'TRY',
        'TUSD',
        'USDC',
        'USDT',
        'ZAR',
    ];

    function __construct()
    {
        global $mongoDBLink;

        $this->mongoDBLink = $mongoDBLink;
    }

    public function checkIfStableCoin($coin)
    {
        return in_array($coin, $this->stableCoins);
    }

    /**
     * Extract or comput the gross profit from a given position
     *
     * @param BSONDocument $position
     * @param PositionMediator $positionMediator
     * @param bool $exitPrice
     * @param bool $forceAccounting
     * @return float
     */
    public function computeGrossProfit(
        BSONDocument $position,
        PositionMediator $positionMediator,
        $exitPrice = false,
        $forceAccounting = false
    ) {
        if (!$forceAccounting && !empty($position->accounting) && !empty($position->accounting->netProfit)) {
            $netProfit = is_object($position->accounting->netProfit) ? $position->accounting->netProfit->__toString() : $position->accounting->netProfit;
            if (empty($position->accounting->fundingFees)) {
                $fundingFee = 0;
            } else {
                $fundingFee = is_object($position->accounting->fundingFees) ? $position->accounting->fundingFees->__toString() : $position->accounting->fundingFees;
            }
            if (empty($position->accounting->totalFees)) {
                $totalFees = 0;
            } else {
                $totalFees = is_object($position->accounting->totalFees) ? $position->accounting->totalFees->__toString() : $position->accounting->totalFees;
            }
            $grossProfit = $netProfit + $totalFees - $fundingFee;
            return (float)$grossProfit;
        }

        if (!$position->closed && !empty($exitPrice)) {
            $grossProfit = $this->estimateProfitFromOpenPosition($positionMediator, $position, $exitPrice);
            return (float)$grossProfit;
        }

        if (empty($position->trades)) {
            return (float)0.00;
        }

        list($buys, $sells) = $this->extractQtyPrices($position->trades);

        if ($positionMediator->isLong()) {
            list($entryTotalQty, $entryAvgPrice) = $this->getComputedCosts($buys, $positionMediator);
            list($exitTotalQty, $exitAvgPrice) = $this->getComputedCosts($sells, $positionMediator);
        } else {
            list($entryTotalQty, $entryAvgPrice) = $this->getComputedCosts($sells, $positionMediator);
            list($exitTotalQty, $exitAvgPrice) = $this->getComputedCosts($buys, $positionMediator);
        }
        /*
        if ($positionMediator->isShort()) {
            $grossProfit = ($entryAvgPrice - $exitAvgPrice) * $exitTotalQty - ($entryTotalQty - $exitTotalQty) * $entryAvgPrice;
        } else {
            $grossProfit = ($exitAvgPrice - $entryAvgPrice) * $exitTotalQty - ($entryTotalQty - $exitTotalQty) * $entryAvgPrice;
        }
        */
        $grossProfit = $positionMediator->getExchangeMediator()->getExchangeHandler()
            ->calculateGrossProfit(
                $positionMediator->getSymbol(),
                $positionMediator->isShort(),
                $entryAvgPrice,
                $exitAvgPrice,
                $entryTotalQty,
                $exitTotalQty
            );

        return (float)$grossProfit;
    }


    /**
     * Extract the profit and amount only from the exited amount.
     *
     * @param BSONDocument $position
     * @return array
     */
    public function computeGrossProfitFromExitedAmount(BSONDocument $position)
    {
        if (empty($position->trades)) {
            return [(float)0.00, (float)0.00];
        }

        list($buys, $sells) = $this->extractQtyPrices($position->trades);

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
        if (empty($position->side) || $position->side == 'LONG') {
            list(, $entryAvgPrice) = $this->getComputedCosts($buys, $positionMediator);
            list($exitTotalQty, $exitAvgPrice) = $this->getComputedCosts($sells, $positionMediator);
            if ($exitTotalQty == 0) {
                return [(float)0.00, (float)0.00];
            }
            // $grossProfitFromExitedAmount = ($exitAvgPrice - $entryAvgPrice) * $exitTotalQty;
            $grossProfitFromExitedAmount = $exchangeHandler->calculateCurrentGrossProfit(
                $positionMediator->getSymbol(),
                false,
                $entryAvgPrice,
                $exitAvgPrice,
                $exitTotalQty
            );
        } else {
            list(, $entryAvgPrice) = $this->getComputedCosts($sells, $positionMediator);
            list($exitTotalQty, $exitAvgPrice) = $this->getComputedCosts($buys, $positionMediator);
            if ($exitTotalQty == 0) {
                return [(float)0.00, (float)0.00];
            }
            // $grossProfitFromExitedAmount = ($entryAvgPrice - $exitAvgPrice) * $exitTotalQty;
            $grossProfitFromExitedAmount = $exchangeHandler->calculateCurrentGrossProfit(
                $positionMediator->getSymbol(),
                true,
                $entryAvgPrice,
                $exitAvgPrice,
                $exitTotalQty
            );
        }

        return [(float)$grossProfitFromExitedAmount, (float)$exitTotalQty];
    }

    /**
     * Complete the remaining unrealized profit and losses for a given position and current market price.
     *
     * @param array $position
     * @param float $currentPrice
     * @return array
     */
    public function computeGrossProfitFromCachedOpenPosition(array $position, float $currentPrice)
    {
        $grossProfitsFromExitAmount = empty($position['grossProfitsFromExitAmount']) ? 0 : $position['grossProfitsFromExitAmount'];
        $exitedAmount = empty($position['exitedAmount']) ? 0 : $position['exitedAmount'];
        $totalAmount = $position['amount'];
        $avgEntryPrice = $position['buyPrice'];
        $remainingAmount = $totalAmount - $exitedAmount;

        $positionCacheMediator = PositionCacheMediator::fromArray($position);
        $exchangeHandler = $positionCacheMediator->getExchangeHandler();
        /*
        if ($position['side'] == 'LONG') {
            $currentGrossProfits = ($currentPrice - $avgEntryPrice) * $remainingAmount;
            $exchangeHandler->
        } else {
            $currentGrossProfits = ($avgEntryPrice - $currentPrice) * $remainingAmount;
        }
        */
        $currentGrossProfits = $exchangeHandler->calculateCurrentGrossProfit(
            $positionCacheMediator->getSymbol(),
            $position['side'] !== 'LONG',
            $avgEntryPrice,
            $currentPrice,
            $remainingAmount
        );

        // $invested = $totalAmount * $avgEntryPrice;
        $positionSize = $exchangeHandler->calculatePositionSize(
            $positionCacheMediator->getSymbol(),
            $totalAmount,
            $avgEntryPrice
        );

        $invested = $exchangeHandler->calculateRealInvestmentFromPositionSize(
            $positionCacheMediator->getSymbol(),
            $positionSize
        );

        $unrealizedProfitLosses = $currentGrossProfits + $grossProfitsFromExitAmount;
        $unrealizedProfitLossesPercentage = $this->getNotInvertedPercentage($invested, $unrealizedProfitLosses);
        $priceDifference = $this->getNotInvertedPercentage($avgEntryPrice, $currentPrice - $avgEntryPrice);
        $PnL = $grossProfitsFromExitAmount;
        $PnLPercentage = $this->getNotInvertedPercentage($invested, $PnL);
        $uPnL = $currentGrossProfits;
        $uPnLPercentage = $this->getNotInvertedPercentage($invested, $uPnL);
        return [
            (float)$unrealizedProfitLosses,
            $unrealizedProfitLossesPercentage,
            $priceDifference,
            $PnL,
            $PnLPercentage,
            $uPnL,
            $uPnLPercentage,
        ];
    }

    /**
     * Compute the real position size from the positions's side, amount acquired and already exited.
     *
     * @param BSONDocument $position
     * @return array|int
     */
    public function estimatedPositionSize(BSONDocument $position)
    {
        if (empty($position->trades))
            return 0;

        $pricesData = $this->extractQtyPrices($position->trades);

        if (!$pricesData[0] && !$pricesData[1]) {
            return 0;
        }

        $unitsEntry = 0;
        $unitsExit = 0;
        $positionSizeEntry = 0;
        $positionSizeExit = 0;

        $totalEntries = $position->side == 'SHORT' ? $pricesData[1] : $pricesData[0];
        $totalExits = $position->side == 'SHORT' ? $pricesData[0] : $pricesData[1];

        foreach ($totalEntries as $entry) {
            $positionSizeEntry += $entry['price'] * $entry['quantity'];
            $unitsEntry += $entry['quantity'];
        }

        if ($totalExits) {
            foreach ($totalExits as $exit) {
                $unitsExit += $exit['quantity'];
                $positionSizeExit += $exit['price'] * $exit['quantity'];
            }
        }

        $unitsUnexited = $unitsEntry - $unitsExit;

        return [
            $positionSizeEntry,
            $positionSizeExit,
            $unitsUnexited,
            $unitsEntry,
        ];
    }

    /**
     * Estimated profits from a position based on current market price.
     *
     * @param \Zignaly\Mediator\PositionMediator $positionMediator
     * @param BSONDocument $position
     * @param int|float $currentPrice
     * @return float|int
     */
    public function estimateProfitFromOpenPosition(
        \Zignaly\Mediator\PositionMediator $positionMediator,
        BSONDocument                       $position,
                                           $currentPrice
    ) {
        if (empty($position->trades)) {
            return 0;
        }
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
        $zigid = $positionMediator->getSymbol();

        $tradesIds = [];
        $buyCost = 0;
        $sellCost = 0;
        $buyAmount = 0;
        $sellAmount = 0;
        foreach ($position->trades as $trade) {
            $tradeIdOrderId = $trade->id.$trade->orderId;
            if (!in_array($tradeIdOrderId, $tradesIds)) {
                $tradesIds[] = $tradeIdOrderId;
                if ($trade->isBuyer) {
                    // $buyCost += $trade->qty * $trade->price;
                    $buyCost += $exchangeHandler->calculateOrderCostZignalyPair($zigid, $trade->qty, $trade->price);
                    $buyAmount += $trade->qty;
                } else {
                    // $sellCost += $trade->qty * $trade->price;
                    $sellCost += $exchangeHandler->calculateOrderCostZignalyPair($zigid, $trade->qty, $trade->price);
                    $sellAmount += $trade->qty;
                }
            }
        }

        if ($positionMediator->isLong() && $buyAmount > $sellAmount) {
            // $sellCost += ($buyAmount - $sellAmount) * $currentPrice;
            $sellCost += $exchangeHandler->calculateOrderCostZignalyPair($zigid, ($buyAmount - $sellAmount), $currentPrice);
        } elseif ($positionMediator->isShort() && $sellAmount > $buyAmount) {
            // $buyCost += ($sellAmount - $buyAmount) * $currentPrice;
            $buyCost += $exchangeHandler->calculateOrderCostZignalyPair($zigid, ($sellAmount - $buyAmount), $currentPrice);
        }

        return $sellCost - $buyCost;
    }

    /**
     * Extract the quantities and price per side.
     *
     * @param object $trades
     * @return array
     */
    public function extractQtyPrices(object $trades)
    {
        $buys = false;
        $sells = false;
        $tradesIds = [];
        foreach ($trades as $trade) {
            $tradeIdOrderId = $trade->id.$trade->orderId;
            if (in_array($tradeIdOrderId, $tradesIds)) {
                continue;
            }
            $tradesIds[] = $tradeIdOrderId;
            $data = [
                'price' => $trade->price,
                'quantity' => $trade->qty,
            ];
            if ($trade->isBuyer) {
                $buys[] = $data;
            } else {
                $sells[] = $data;
            }
        }

        return [$buys, $sells];
    }

    /**
     * Given an array with prices and quantities, return the average price and total quantity.
     *
     * @param array|bool $data
     * @param PositionMediator $positionMediator
     * 
     * @return array
     */
    public function getComputedCosts($data, $positionMediator = null)
    {
        if (!$data)
            return [0, 0];

        $totalQty = 0;
        $totalCost = 0;
        if ($positionMediator != null) {
            $exchangeHandler = $positionMediator
                ->getExchangeMediator()->getExchangeHandler();
            foreach ($data as $datum) {
                $cost = $exchangeHandler->calculateOrderCostZignalyPair(
                    $positionMediator->getSymbol(),
                    $datum['quantity'],
                    $datum['price']
                );
                $totalCost += $cost;
                $totalQty += $datum['quantity'];
            }
            $avgPrice = $totalQty > 0 ? 
                $exchangeHandler->calculatePriceFromCostAmount(
                    $positionMediator->getSymbol(),
                    $totalCost,
                    $totalQty
                )
                : false;
        } else {
            foreach ($data as $datum) {
                $cost = $datum['price'] * $datum['quantity'];
                $totalCost += $cost;
                $totalQty += $datum['quantity'];
            }
            $avgPrice = $totalQty > 0 ? $totalCost / $totalQty : false;
        }

        return [
            number_format($totalQty, 12, '.', ''),
            number_format($avgPrice, 12, '.', ''),
        ];
    }

    /**
     * Get the real amount from a given order.
     *
     * @param BSONDocument $position
     * @param string $orderId
     * @return int
     */
    public function getRealAmount(BSONDocument $position, string $orderId)
    {
        if (!isset($position->trades) || !$position->trades) {
            return 0;
        }
        $realAmount = 0;
        $tradesId = [];
        foreach ($position->trades as $trade) {
            $tradeIdOrderId = $trade->id.$trade->orderId;
            if ($orderId == $trade->orderId && !in_array($tradeIdOrderId, $tradesId)) {
                $tradesId[] = $tradeIdOrderId;
                $realAmount += $trade->qty;
            }
        }

        return $realAmount;
    }

    public function getAveragePrice($position, $type)
    {
        $positionSize = 0;
        $tradesId = [];
        $amount = 0;

        if (isset($position->trades) && $position->trades) {
            foreach ($position->trades as $trade) {
                $trade->price = number_format($trade->price, 12, '.', '');
                $tradeIdOrderId = $trade->id.$trade->orderId;
                if (!in_array($tradeIdOrderId, $tradesId)) {
                    $tradesId[] = $tradeIdOrderId;
                    if ($type == 'buy' && $trade->isBuyer) {
                        $positionSize += $trade->qty * $trade->price;
                        $amount += $trade->qty;
                    }
                    if ($type == 'sell' && !$trade->isBuyer) {
                        $positionSize += $trade->qty * $trade->price;
                        $amount += $trade->qty;
                    }
                }
            }
        }
        return $amount === 0 ? 0 : $positionSize / $amount;
    }

    /**
     * Extract the entry price from a given position.
     *
     * @param BSONDocument $position
     * @return array
     */
    public function getEntryPrice(BSONDocument $position): array
    {
        $orderId = false;

        if (empty($position->orders)) {
            return [false, false];
        }

        if (empty($position->trades)) {
            return [false, false];
        }

        foreach ($position->orders as $order) {
            if (!$orderId && ($order->type == 'buy' || $order->type == 'entry') && $order->done) {
                if ('MULTI' === $position->buyType && 'closed' !== $order->status) {
                    continue;
                }
                $orderId = $order->orderId;
            }
        }

        $invested = 0;
        $quantity = 0;
        $tradesId = [];

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

        foreach ($position->trades as $trade) {
            if ($trade->orderId == $orderId) {
                $tradeIdOrderId = $trade->id.$trade->orderId;
                if (!in_array($tradeIdOrderId, $tradesId)) {
                    $tradesId[] = $tradeIdOrderId;
                    $invested += $exchangeHandler->calculatePositionSize(
                        $positionMediator->getSymbol(),
                        $trade->qty,
                        $trade->price
                    );
                    $quantity += $trade->qty;
                }
            }
        }

        $leverage = isset($position->leverage) && $position->leverage > 0 ? $position->leverage : 1;

        return $quantity == 0 ? [false, false] : [$invested / $quantity, $invested / $leverage];
    }

    /**
     * Download the total list of trades and replace it.
     * @param newPositionCCXT $newPositionCCXT
     * @param ExchangeCalls $ExchangeCalls
     * @param BSONDocument $position
     * @param bool $force
     * @param bool $enableEcho
     * @param bool $forceRestApi
     * @return bool
     */
    public function checkIfRemainingAmountFromTradesIsZero(
        newPositionCCXT $newPositionCCXT,
        ExchangeCalls $ExchangeCalls,
        BSONDocument $position,
        bool $force,
        bool $enableEcho,
        bool $forceRestApi = false
    ) {
        if (empty($position->orders)) {
            if ($enableEcho) {
                echo "No orders found\n";
            }
            return false;
        }

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeName = $positionMediator->getExchange()->getId();
        $exchangeAccountType = $positionMediator->getExchangeType();
        $ExchangeCalls->setCurrentExchange($exchangeName, $exchangeAccountType, $position->testNet);

        $totalTrades = [];
        $totalBuy = (float) 0.0;
        $totalSell = (float) 0.0;

        foreach ($position->orders as $order) {
            $trades = $ExchangeCalls->getTrades($position, $order->orderId, false, $forceRestApi);
            if (empty($trades)) {
                continue;
            }
            foreach ($trades as $trade) {
                $totalTrades[] = $trade;
                if ($trade['isBuyer']) {
                    $totalBuy += (float)$trade['qty'];
                } else {
                    $totalSell += (float)$trade['qty'];
                }
            }
        }

        $difference = empty($totalSell) ? 0 : abs(($totalBuy - $totalSell) / $totalSell);
        if ($force || (!empty($trades) && $difference < 0.000001)) {
            $setPosition = [
                'trades' => $totalTrades,
                'accounted' => false,
                'sellPerformed' => $position->closed,
            ];
            $newPositionCCXT->setPosition($position->_id, $setPosition, false);
            if ($enableEcho) {
                echo "OK\n";
            }
            return true;
        } else {
            if ($enableEcho) {
                print_r($totalTrades);
                $difference2 = $totalBuy - $totalSell;
                echo "KO: $totalBuy - $totalSell = $difference2 ($difference)\n";
            }
            return false;
        }
    }

    /**
     * Get the price from the last entry order.
     *
     * @param BSONDocument $position
     * @return bool|float|int
     */
    public function getLastEntryPrice(BSONDocument $position)
    {
        if (empty($position->orders) || empty($position->trades)) {
            return false;
        }

        foreach ($position->orders as $order) {
            if ($order->type == 'entry' && $order->status == 'closed') {
                $orderId = $order->orderId;
            }
        }

        if (empty($orderId)) {
            return false;
        }

        $invested = 0;
        $quantity = 0;
        $tradesId = [];
        foreach ($position->trades as $trade) {
            $tradeIdOrderId = $trade->id.$trade->orderId;
            if ($trade->orderId != $orderId || in_array($tradeIdOrderId, $tradesId)) {
                continue;
            }
            $tradesId[] = $tradeIdOrderId;
            $invested += $trade->qty* $trade->price;
            $quantity += $trade->qty;
        }

        if ($quantity > 0 && $invested > 0) {
            return $invested / $quantity;
        }

        return false;
    }


    /**
     * Extract the fees in the proper format from the accounting object.
     *
     * @param BSONDocument $position
     * @return int|float
     */
    public function getFeesFromPosition(BSONDocument $position)
    {
        if (isset($position->accounting->totalFees)) {
            $fees = is_object($position->accounting->totalFees) ? $position->accounting->totalFees->__toString() : $position->accounting->totalFees;
            return number_format($fees, 12, '.', '');
        }

        return 0;
    }

    public function getLowerOrHigherValue($values, $type)
    {
        foreach ($values as $value) {
            if (!isset($returnValue)) {
                $returnValue = $value;
            } elseif ($type == 'lower' && $value < $returnValue) {
                $returnValue = $value;
            } elseif ($type == 'higher' && $value > $returnValue) {
                $returnValue = $value;
            }
        }

        return isset($returnValue) ? $returnValue : 0;
    }

    /**
     * Return the percentage between two numbers.
     *
     * @param $original
     * @param $new
     * @return float|int
     */
    private function getNotInvertedPercentage($original, $new)
    {
        $original = round($original, 8);

        if (empty($original) || empty($new)) {
            return 0;
        }

        $percentage = round($new * 100 / $original, 2);

        return (float) $percentage;
    }

    function getPercentage($original, $new)
    {
        if (!$new)
            return false;

        $percentage = round(($new / $original - 1) * 100, 2);

        return $percentage;
    }

    /**
     * Extract the position size from the trades.
     *
     * @param BSONDocument $position
     * @param string $side
     * @return float|int
     */
    public function getPositionSize(BSONDocument $position, string $side)
    {
        $positionSize = 0;
        $tradesId = [];

        if (!empty($position->trades)) {
            $positionMediator = PositionMediator::fromMongoPosition($position);
            $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
            foreach ($position->trades as $trade) {
                $tradeIdOrderId = $trade->id.$trade->orderId;
                if (!in_array($tradeIdOrderId, $tradesId)) {
                    $tradesId[] = $tradeIdOrderId;
                    if (($side == 'buy' && $trade->isBuyer) || ($side == 'sell' && !$trade->isBuyer)) {
                        $positionSize += $exchangeHandler->calculatePositionSize(
                            $positionMediator->getSymbol(),
                            $trade->qty,
                            $trade->price
                        );
                    }
                }
            }
        }

        return $positionSize;
    }

    public function getTotalAmountWithoutNonBNBFees($positionId, $position = false)
    {
        $position = !$position ? $this->getPosition($positionId) : $position;

        $totalAmount = 0;
        $tradesId = [];

        if (empty($position->side)) {
            $position->side = 'LONG';
        }

        if (isset($position->trades) && $position->trades) {
            $positionMediator = PositionMediator::fromMongoPosition($position);
            $exchangeType = $positionMediator->getExchangeType();
            foreach ($position->trades as $trade) {
                $tradeIdOrderId = $trade->id.$trade->orderId;
                if (!in_array($tradeIdOrderId, $tradesId)) {
                    $tradesId[] = $tradeIdOrderId;
                    if (($position->side == 'LONG' && $trade->isBuyer) || ($position->side == 'SHORT' && !$trade->isBuyer)) {
                        $totalAmount += $trade->qty;

                        // TODO: how to do this automatically?
                        if ($trade->commissionAsset == 'YOYOW') {
                            $trade->commissionAsset = 'YOYO';
                        }

                        if (($exchangeType != 'futures') && $trade->commissionAsset == $position->signal->base) {
                            $totalAmount -= $trade->commission;
                        }
                    }
                }
            }
        }

        return $totalAmount;
    }

    public function getTotalAssetsAndProfitFromTotal($position, $netProfit, Monolog $Monolog)
    {
        if (!empty($position->accounting) && !empty($position->accounting->totalAllocatedBalance) && !empty($position->accounting->profitFromTotalAllocatedBalance)) {
            $totalAllocatedBalance = is_object($position->accounting->totalAllocatedBalance) ? $position->accounting->totalAllocatedBalance->__toString() : $position->accounting->totalAllocatedBalance;
            $profitFromTotalAllocatedBalance = is_object($position->accounting->profitFromTotalAllocatedBalance) ? $position->accounting->profitFromTotalAllocatedBalance->__toString() : $position->accounting->profitFromTotalAllocatedBalance;

            if ($totalAllocatedBalance && $profitFromTotalAllocatedBalance) {
                return [$totalAllocatedBalance, $profitFromTotalAllocatedBalance];
            }
        }

        list(, $originalInvestedAmount) = $this->getEntryPrice($position);
        $totalAllocatedBalance = isset($position->profitSharingData->sumUserAllocatedBalance) ? $position->profitSharingData->sumUserAllocatedBalance : false;
        $profitFromTotalAllocatedBalance = false;

        if (isset($position->signal->positionSizePercentage)) {
            $positionSizePercentage = $position->signal->positionSizePercentage;
            if (!is_numeric($originalInvestedAmount) || !is_numeric($positionSizePercentage))
                $Monolog->sendEntry('debug', "Non numeric value ( $originalInvestedAmount / $positionSizePercentage) for position " . $position->_id->__toString());
            if (empty($totalAllocatedBalance)) {
                $totalAllocatedBalance = $originalInvestedAmount / $positionSizePercentage * 100;
            }
            $profitFromTotalAllocatedBalance = $netProfit * 100 / $totalAllocatedBalance;
        }

        return [$totalAllocatedBalance, $profitFromTotalAllocatedBalance];
    }

    public function getPosition($positionId)
    {
        $positionId = is_object($positionId) ? $positionId : new \MongoDB\BSON\ObjectId($positionId);

        return $this->mongoDBLink->selectCollection('position')->findOne(['_id' => $positionId]);
    }

    public function recalculateAndUpdateAmounts($position)
    {
        $remainAmount = 0;
        $totalAmount = 0;
        $tradesId = [];
        $side = isset($position->side) ? $position->side : 'LONG';

        $positionMediator = PositionMediator::fromMongoPosition($position);

        // avoid to subtract the BNB commission when binance futures and BNB position
        $exchangeName = !empty($position->exchange->exchangeName) ? $position->exchange->exchangeName : $position->exchange->name;
        $isBinanceExchange = ZignalyExchangeCodes::isBinance(ZignalyExchangeCodes::getRealExchangeName($exchangeName));

        $isFutures = 'futures' == $positionMediator->getExchangeType();
        $avoidSubtractCommission = $isBinanceExchange && $isFutures && isset($position->signal->base) && 'BNB' === $position->signal->base;

        if (isset($position->trades)) {
            foreach ($position->trades as $trade) {
                $tradeIdOrderId = $trade->id.$trade->orderId;
                if (!in_array($tradeIdOrderId, $tradesId)) {
                    $tradesId[] = $tradeIdOrderId;
                    if (($side == 'LONG' && $trade->isBuyer) || ($side == 'SHORT' && !$trade->isBuyer)) {
                        $remainAmount += $trade->qty;
                        $totalAmount += $trade->qty;
                        if (($trade->commissionAsset == $position->signal->base) && !$avoidSubtractCommission) {
                            $remainAmount -= $trade->commission;
                        }
                    } else {
                        $remainAmount -= $trade->qty;
                    }
                }
            }
        }

        $totalAmount = number_format($totalAmount, 12, '.', '');
        $remainAmount = number_format($remainAmount, 12, '.', '');

        return [$totalAmount, $remainAmount];
    }

    /**
     * Get the locked amount from a given position.
     *
     * @param BSONDocument $position
     * @return int
     */
    public function getLockedAmountFromPosition(BSONDocument $position)
    {
        if (empty($position->orders)) {
            return 0;
        }

        $lockedAmount = 0;

        foreach ($position->orders as $order) {
            if ($order->done) {
                continue;
            }

            if ('entry' === $order->type || 'stop' === $order->type) {
                continue;
            }

            if (strtolower($order->status) == 'cancelled'
                || strtolower($order->status) == 'canceled'
                || strtolower($order->status) == ExchangeOrderStatus::Expired
            ) {
                continue;
            }

            $lockedAmount += $order->amount;
        }

        return $lockedAmount;
    }

    /**
     * Get the locked investment from the entry open orders from the position.
     *
     * @param BSONDocument $position
     * @return int
     */
    public function getLockedInvestmentFromEntries(BSONDocument $position)
    {
        if (empty($position->orders)) {
            return 0;
        }

        $lockedInvestment = 0;

        foreach ($position->orders as $order) {
            if ($order->done) {
                continue;
            }

            if ($order->type != 'entry') {
                continue;
            }

            if (strtolower($order->status) == 'cancelled'
                || strtolower($order->status) == 'canceled'
                || strtolower($order->status) == ExchangeOrderStatus::Expired
            ) {
                continue;
            }
            $leverage = empty($position->leverage) ? 1 : $position->leverage;
            $positionMediator = PositionMediator::fromMongoPosition($position);
            $exchangeHandler = $positionMediator
                ->getExchangeMediator()->getExchangeHandler();
            // $lockedInvestment += ($order->amount * $order->price) / $leverage;
            $lockedInvestment += $exchangeHandler->calculatePositionSize(
                $positionMediator->getSymbol(),
                $order->amount,
                $order->price
            ) / $leverage;
        }

        return $lockedInvestment;
    }

    public function statsCalculateAverage($values)
    {
        return count($values) == 0 ? 0 : array_sum($values) / count($values);
    }

    public function getFundingFeesFromExchange(
        ExchangeCalls $ExchangeCalls,
        PositionMediator $positionMediator,
        int $from,
        int $to,
        Monolog $Monolog
    ) {
        $position = $positionMediator->getPositionEntity();
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
        $fundingFeesTag = $exchangeHandler->getFundingFeesTag();
        $feesEntries = $ExchangeCalls->userIncome($positionMediator->getSymbolWithSlash(), $fundingFeesTag, $from, $to);
        if (is_array($feesEntries) && array_key_exists('error', $feesEntries)) {
            if (empty($position->paperTrading)) {
                $Monolog->sendEntry('critical', 'Error retrieving funding fees: ', $feesEntries);
            }
    
            return 0.0;
        }
        $totalFees = 0.0;
        foreach ($feesEntries as $feesEntry) {
            //TODO: We don't have into consideration here the Hedge mode, if we implement it, we could count the funding fee twice.
            $totalFees = $totalFees + $exchangeHandler->calculateFundingFeeForExchangeIncome(
                $positionMediator->getSymbol(),
                $positionMediator->isShort(),
                $feesEntry
            ); // $feesEntry->getIncome();
        }
    
        return $totalFees;
    }
}