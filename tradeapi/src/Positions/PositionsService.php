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


namespace Zignaly\Positions;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;

/**
 * Class PositionsService
 * @package Zignaly\Positions
 */
class PositionsService
{
    /**
     * @var Database
     */
    private $mongoDBLink;

    /**
     * PositionsService constructor.
     */
    public function __construct()
    {
        global $mongoDBLink;

        $this->mongoDBLink = $mongoDBLink;
    }

    /**
     * Return the list of closed positions where the accounting exists, os they have been sold.
     *
     * @param ObjectId $userId
     * @param string|null $internalExchangeId
     * @param int|null $date //In Unix time
     * @return float
     * @throws \Exception
     */
    public function getProfitFromDate(ObjectId $userId, ?string $internalExchangeId = null, ?int $date = null): float
    {
        $find = [
            'user._id' => $userId,
            'closed' => true,
            'accounting.done' => true,
            'accounting.closingDate' => ['$gte' => new UTCDateTime(($date ?? strtotime('today midnight')) * 1000)],
        ];
        if ($internalExchangeId) {
            $find['exchange.internalId'] = $internalExchangeId;
        }

        $options = [
            'sort' => [
                'accounting.closingDate' => -1
            ],
            'limit' => 500
        ];

        $positions = $this->mongoDBLink->selectCollection('position')->find($find, $options);

        $profit = 0.0;

        foreach ($positions as $position) {
            if (empty($position->exchange)) {
                continue;
            }

            if (isset($position->accounting) && is_object($position->accounting)) {
                $netProfit = is_object($position->accounting->netProfit) ? $position->accounting->netProfit->__toString() : $position->accounting->netProfit;
            } else {
                $netProfit = 0;
            }


            $profit += (float) $netProfit;
        }

        return $profit;
    }
}
