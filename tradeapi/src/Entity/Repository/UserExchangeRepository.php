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


namespace Zignaly\Entity\Repository;

use MongoDB\Database;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use MongoDB\Operation\FindOneAndUpdate;

/**
 * Provides storage retrieval of user exchanges connection entities.
 *
 * @package Zignaly\Entity\Repository
 */
class UserExchangeRepository
{
    /**
     * Storage connection.
     *
     * @var \MongoDB\Database
     */
    private $storage;

    /**
     * UserExchangeRepository constructor.
     */
    public function __construct(Database $dbConnection)
    {
        $this->storage = $dbConnection;
    }

    /**
     * Find sub-account by ID.
     *
     * @param string $subAccountId The sub-account ID to lookup.
     *
     * @return \MongoDB\Model\BSONDocument|null User exchange for the sub-account.
     */
    public function findSubAccountById(string $subAccountId)
    {
        $find = [
            'exchanges' => [
                '$elemMatch' => [
                    'subAccountId' => $subAccountId,
                ],
            ],
        ];

        $options = [
            'projection' => [
                '_id' => false,
                'exchanges' => $find['exchanges'],
            ],
        ];

        $cursor = $this->storage->selectCollection('user')->find($find, $options);
        $usersExchangesRaw = $cursor->toArray();

        if (isset($usersExchangesRaw[0]->exchanges)) {
            return $usersExchangesRaw[0]->exchanges[0];
        }

        return null;
    }

    /**
     * Find sub-accounts by exchange type.
     *
     * @param string $exchangeType Exchange type: (futures, spot)
     * @param bool|string $userId
     *
     * @return \MongoDB\Model\BSONDocument[] Array of user exchanges.
     */
    public function findSubAccountByType(string $exchangeType, $userId = false)
    {
        $find = [
            'exchanges' => [
                '$elemMatch' => [
                    'exchangeType' => $exchangeType,
                    'isBrokerAccount' => true,
                ]
            ]
        ];

        if ($userId) {
            $find['_id'] = new \MongoDB\BSON\ObjectId($userId);
        }

        $set = [
            '$set' => [
                'lastCheckFuturesExchangeForTransferAt' => new \MongoDB\BSON\UTCDateTime(),
            ]
        ];

        $options = [
            'projection' => [
                '_id' => true,
                'exchanges' => true,
            ],
        ];

        if (!$userId) {
            $options['sort'] = [
                'lastCheckFuturesExchangeForTransferAt' => 1
            ];
        }

        $userExchangesRaw = $this->storage->selectCollection('user')->findOneAndUpdate($find, $set, $options);

        $userExchanges = [];
        if (empty($userExchangesRaw->exchanges)) {
            return [false, $userExchanges];
        }

        // TODO: Extract the transformations to UserExchangeEntity type in the refactoring.
        /** @var \MongoDB\Model\BSONDocument $document */
        //foreach ($usersExchangesRaw as $userExchangesRaw) {
            /*if (!isset($userExchangesRaw->exchanges)) {
                continue;
            }*/

            /*if (!$userExchangesRaw->exchanges instanceof BSONArray) {
                continue;
            }*/

            /** @var \MongoDB\Model\BSONDocument $userExchangeRaw */
            foreach ($userExchangesRaw->exchanges as $userExchangeRaw) {
                if ($this->isSubAccountOfType($userExchangeRaw, $exchangeType)) {
                    // For now we don't transform to custom entities objects due many existing
                    // methods are expecting BSONDocument so this type it's more convenient for now.
                    $userExchanges[] = $userExchangeRaw;
                }
            }
        //}

        return [$userExchangesRaw->_id->__toString(), $userExchanges];
    }

    /**
     * Check if user exchange is sub-account of a given exchange type.
     *
     * @param \MongoDB\Model\BSONDocument $userExchange User exchange entity to check.
     * @param string $exchangeType Desired exchange type to check.
     *
     * @return bool TRUE when is of a given type, FALSE otherwise.
     */
    private function isSubAccountOfType(BSONDocument $userExchange, string $exchangeType): bool
    {
        $currentExchangeType = 'spot';
        if (isset($userExchange->exchangeType)) {
            $currentExchangeType = trim($userExchange->exchangeType);
        }

        return !empty($userExchange->subAccountId) && $currentExchangeType === $exchangeType;
    }
}