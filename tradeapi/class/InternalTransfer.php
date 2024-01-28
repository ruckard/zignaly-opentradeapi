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


use MongoDB\Driver\Cursor;

class InternalTransfer
{
    private $collectionName = 'internalTransfer';
    /** @var \MongoDB\Database  */
    private $mongoDBLink;

    function __construct()
    {
        global $mongoDBLink;

        $this->mongoDBLink = $mongoDBLink;
    }

    /**
     * Insert the given document in the cashBackPayments collection.
     *
     * @param array $document
     * @return \MongoDB\InsertOneResult
     */
    public function insert(array $document)
    {
        return $this->mongoDBLink->selectCollection($this->collectionName)->insertOne($document);
    }

    /**
     * @param array $subAccountIds
     * @return Cursor
     */
    public function getEntriesFromSubAccounts(array $subAccountIds): Cursor
    {
        $find = [
            '$or' => [
                [
                    'from' => [
                        '$in' => $subAccountIds
                    ],
                ],
                [
                    'to' => [
                        '$in' => $subAccountIds,
                    ],
                ],
            ],
            'type' => [
                '$in' => ['psWithdraw', 'psDeposit']
            ],
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find);
    }
}