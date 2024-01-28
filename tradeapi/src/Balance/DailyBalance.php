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


namespace Zignaly\Balance;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\DeleteResult;
use MongoDB\Driver\Cursor;

/**
 * Class DailyBalance
 */
class DailyBalance
{
    private $mongoDBLink;
    private $collectionName = 'dailyBalance';

    /**
     * DailyBalance constructor.
     */
    public function __construct()
    {
        global $mongoDBLink;
        $this->mongoDBLink = $mongoDBLink;
    }

    /**
     * @param $id
     * @return DeleteResult
     */
    public function deleteEntry($id): DeleteResult
    {
        $find = [
            '_id' => \is_object($id) ? $id : new ObjectId($id)
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->deleteOne($find);
    }

    /**
     * Get the last N entries from the user in the balance.
     *
     * @param ObjectId $userId
     * @param int $limit
     * @return Cursor
     */
    public function getLastNEntriesForUser(ObjectId $userId, int $limit = 60): Cursor
    {
        $find = [
            'userId' => $userId,
        ];

        $options = [
            'sort' => [
                'date' => -1
            ],
            'limit' => $limit
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * @param $userId
     * @return int|string
     */
    public function getTotalAssets($userId)
    {
        $lastDaysBalance = $this->getLastNEntriesForUser($userId, 1);

        foreach ($lastDaysBalance as $lastDayBalance) {
            $lastBalance = $lastDayBalance;
        }

        if (!isset($lastBalance, $lastBalance->balances) || !$lastBalance->balances) {
            return 0;
        }

        $total = 0;

        foreach ($lastBalance->balances as $balance) {
            if (isset($balance->total, $balance->total->totalBTC)) {
                $totalBTC = is_object($balance->total->totalBTC) ? $balance->total->totalBTC->__toString() : $balance->total->totalBTC;
                $total += $totalBTC;
            }
        }

        return number_format($total, 8, '.', '');
    }

    /**
     * Return all the entries for the given day
     * @param string $dateKey
     * @return Cursor
     */
    public function getAllEntriesForGivenDay(string $dateKey)
    {
        $find = [
            'dateKey' => $dateKey,
        ];

        $options = [
            //'noCursorTimeout' => true,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    /**
     * @param $userId
     * @param $exchange
     * @param $balance
     * @return bool
     */
    public function updateDailyBalanceForUser($userId, $exchange, $balance): ?bool
    {
        $dateKey = date('Y-m-d');
        $find = compact('userId', 'dateKey');

        $entry = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);

        if (isset($entry->dateKey) && $entry->dateKey === $dateKey) {
            $set = [
                '$set' => [
                    $exchange => $balance
                ],
            ];
            $result = $this->mongoDBLink
                    ->selectCollection($this->collectionName)->updateOne($find, $set)->getModifiedCount() > 0;
        } else {
            $entry = [
                'date' => new UTCDateTime(),
                'dateKey' => $dateKey,
                'userId' => $userId,
                $exchange => $balance,
            ];
            $result = $this->mongoDBLink
                    ->selectCollection($this->collectionName)->insertOne($entry)->getInsertedCount() > 0;
        }

        return $result;
    }

    /**
     * @param $userId
     * @param $exchangeInternalId
     * @param $balance
     * @return bool
     */
    public function updateDailyBalanceForUserFromExchange($userId, $exchangeInternalId, $balance): bool
    {
        $dateKey = date('Y-m-d');
        $find = compact('userId', 'dateKey');

        $entry = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);

        if (!isset($entry->dateKey)) {
            $entry = [
                'date' => new UTCDateTime(),
                'dateKey' => $dateKey,
                'userId' => $userId,
            ];
            $this->mongoDBLink->selectCollection($this->collectionName)->insertOne($entry);
        }

        if ($this->checkIfExchangeInternalIdExists($entry, $exchangeInternalId)) {
            $find['balances.exchangeInternalId'] = $exchangeInternalId;
            $set = [
                '$set' => [
                    'balances.$' => $balance
                ]
            ];
        } else {
            $set = [
                '$push' => [
                    'balances' => $balance
                ]
            ];
        }

        return $this->mongoDBLink
                ->selectCollection($this->collectionName)->updateOne($find, $set)->getModifiedCount() > 0;
    }

    /**
     * @param $entry
     * @param $exchangeInternalId
     * @return bool
     */
    private function checkIfExchangeInternalIdExists($entry, $exchangeInternalId): bool
    {
        if (isset($entry->balances) && $entry->balances) {
            foreach ($entry->balances as $balance) {
                if (isset($balance->exchangeInternalId) && $balance->exchangeInternalId === $exchangeInternalId) {
                    return true;
                }
            }
        }

        return false;
    }
}
