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


use MongoDB\Model\BSONDocument;

class Formula
{
    /**
     * Get the average price and the total quantity for the given type.
     *
     * @param BSONDocument $position
     * @param string $type
     * @return array
     */
    public function getAveragePriceAndTotalAmount(BSONDocument $position, string $type)
    {
        if (empty($position->trades)) {
            return [0.0, 0.0];
        }

        $tradesId = [];
        $invested = 0;
        $quantity = 0;

        foreach ($position->trades as $trade) {
            $tradeIdOrderId = $trade->id.$trade->orderId;
            if (in_array($tradeIdOrderId, $tradesId)) {
                continue;
            }
            $tradesId[] = $tradeIdOrderId;
            if ($this->checkIfTradeMatchesSide($trade->isBuyer, $position->side, $type)) {
                $invested += $trade->qty * $trade->price;
                $quantity += $trade->qty;
            }
        }

        return $quantity == 0 ? [0.0, 0.0] : [$invested / $quantity, $quantity];
    }

    /**
     * Check if the parameters from a trade belong to an entry or exit type based on side.
     * @param bool $isBuyer
     * @param string $side
     * @param string $type
     * @return bool
     */
    private function checkIfTradeMatchesSide(bool $isBuyer, string $side, string $type)
    {
        if ($type == 'entry') {
            if ($side == 'LONG' && $isBuyer) {
                return true;
            } elseif ($side == 'SHORT' && !$isBuyer) {
                return true;
            } else {
                return false;
            }
        } else { // Exit
            if ($side == 'SHORT' && $isBuyer) {
                return true;
            } elseif ($side == 'LONG' && !$isBuyer) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Return the potential amount for sharing.
     *
     * @param float $totalUnrealizedProfit
     * @param float $totalRealizedProfit
     * @param float $retain
     * @return array
     */
    public function profitSharingAccounting(float $totalUnrealizedProfit, float $totalRealizedProfit, float $retain)
    {
        //If the total PnL plus the retain is less than 0, then it doesn't matter how the uPnL is, is a loss and we
        //account the loss, and because we are using the existing retain, we reset it to 0.
        if ($totalRealizedProfit + $retain <= 0) {
            //If retain is positive, it will reduce losses (because if we are here, the PnL is a loss), the other
            //option is that the retain is 0 (it can't be negative), so it will do nothing.
            $potentialShare = $totalRealizedProfit + $retain;
            $retain = 0;
            return [$potentialShare, $retain];
        }

        //The potential amount to share will be either the maximum of option 1: the PnL plus the current retain plus the
        //lower between uPnL and 0 (because we only account uPnL if it's a loss (so we don't share profits when the
        //trader has uPnL in losses), that's why we limit it to 0); or option 2: $potentialShare will always be converted
        //to 0 if $totalRealizedProfit + $retain is less than min($totalUnrealizedProfit, 0), because this mean that we
        //have profits from PnL and retain but the losses from uPnL are biggest, so we share 0 from them (because we
        //don't share the losses from uPnL.
        $potentialShare = max($totalRealizedProfit + $retain + min($totalUnrealizedProfit, 0), 0);

        if ($potentialShare == 0) {
            //It means that even when PnL + retain is positive, the amount from losses is bigger, so we just update the
            //retain value, and wait until the next sharing process for this provider to see what happens with the
            //uPnL.
            $retain += $totalRealizedProfit;
        } else {
            //If uPnL is negative, then it would have limited our amount to share, so we need to account the retained
            //amount to have it into consideration in the next sharing process. If it's positive (or 0), then it means
            //that anything need to be retained.
            $retain = -1 * min($totalUnrealizedProfit, 0);
        }

        return [$potentialShare, $retain];
    }
}
