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


use MongoDB\BSON\ObjectId;
use MongoDB\Database;

class DepositHistory
{
    private $collectionName = 'depositHistory';

    /** @var Database  */
    private $mongoDBLink;

    function __construct()
    {
        global $mongoDBLink;

        $this->mongoDBLink = $mongoDBLink;
    }

    /**
     * Insert the given document in the depositHistory collection.
     *
     * @param Monolog $Monolog
     * @param array $document
     * @return int
     */
    public function insert(Monolog $Monolog, array $document): int
    {
        try {
            return $this->mongoDBLink->selectCollection($this->collectionName)->insertOne($document)->getInsertedCount();
        } catch (Exception $e) {
            if (!str_contains($e->getMessage(), 'duplicate key error collection')) {
                $Monolog->sendEntry('critical', "Inserting deposit failed: " . $e->getMessage());
            }
            return 0;

        }
    }

    /**
     * @param string $userId
     * @param string $exchangeInternalId
     * @return null|int
     */
    public function getLastDepositDate(string $userId, string $exchangeInternalId): ?int
    {
        $find = [
            'userId' => new ObjectId($userId)
        ];

        if ($exchangeInternalId) {
            $find['exchangeInternalId'] = $exchangeInternalId;
        }

        $options = [
            'sort' => [
                'insertTime' => -1,
            ],
            'limit' => 1
        ];

        $deposits = $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);

        foreach ($deposits as $deposit) {
            if ($deposit->insertTime) {
                return $deposit->insertTime + 1;
            }
        }

        return null;
    }

}