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
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;
use MongoDB\Operation\FindOneAndUpdate;

class newUser
{
    private $collectionName = 'user';
    private $Monolog;
    private $mongoDBLink;
    private $Security;

    function __construct(Monolog $Monolog = null)
    {
        global $mongoDBLink;

        $this->mongoDBLink = $mongoDBLink;
        $this->Monolog = $Monolog;
        $this->Security = new Security();
    }

    /**
     * Find out if the exchange connected to a copy-trader is real or demo.
     *
     * @param BSONDocument $follower
     * @param string $providerId
     * @return bool
     */
    public  function checkIfConnectedExchangeIsReal(BSONDocument $follower, string $providerId)
    {
        if (!isset($follower->provider->$providerId)) {
            return false;
        }

        if (empty($follower->provider->$providerId->exchangeInternalId)) {
            return false;
        }

        $internalExchangeId = $follower->provider->$providerId->exchangeInternalId;

        if (empty($follower->exchanges)) {
            return false;
        }

        foreach ($follower->exchanges as $exchange) {
            if ($exchange->internalId == $internalExchangeId) {
                if (!empty($exchange->paperTrading)) {
                    return false;
                }
                if (!empty($exchange->isTestnet)) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Return the list of followers for a given provider per day.
     *
     * @param string $providerId
     * @return Traversable
     */
    public function getFollowersPerProviderByDay(string $providerId)
    {
        $match = [
            'provider.' . $providerId . '.createdAt' => [
                '$exists' => true
            ],
        ];

        $pipeline = [
            [
                '$match' => $match
            ],
            [
                '$group' => [
                    '_id' => [
                        '$dateToString' => [
                            'format' => '%Y-%m-%d',
                            'date' => '$provider.' . $providerId . '.createdAt'
                        ]
                    ],
                    'followers' => [
                        '$sum' => 1
                    ]
                ]
            ],
            [
                '$sort' => [
                    '_id' => 1
                ]
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->aggregate($pipeline);
    }

    public function checkIfKeysAreValidForExchange($userId, $internalExchangeId)
    {
        $userId = !is_object($userId) ? new ObjectId($userId) : $userId;

        //db.test.find({}, {modules: {$elemMatch: {name: 'foo'}}})
        $find = [
            '_id' => $userId,
            'exchanges' => [
                '$elemMatch' => [
                    'internalId' => $internalExchangeId,
                    'areKeysValid' => true,
                ]
            ]
        ];

        $user = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);

        return isset($user->status);
    }

    public function configureLogging($Monolog)
    {
        $this->Monolog = $Monolog;
    }

    public function getExchangeApiSecretKey($userId, $exchangeId)
    {
        $user = $this->getUser($this->parseMongoDBObject($userId));
        $exchangeId = $this->parseMongoDBObject($exchangeId)->__toString();

        return isset($user->exchange->$exchangeId->key) && isset($user->exchange->$exchangeId->secret) ?
            [$user->exchange->$exchangeId->key, $user->exchange->$exchangeId->secret] : [false, false];
    }

    /**
     * Count connected exchanges for non profit sharing service
     *
     * @param string $providerId
     * @param boolean $disableOnly
     * @return integer
     */
    public function providerConnectedExchangesCount($providerId)
    {
        $find = [
            'provider.' . $providerId . '.name' => [
                '$exists' => true,
            ],
            'provider.' . $providerId . '.exchangeInternalIds' => ['$exists'=> false],
            'provider.' . $providerId . '.disable' => false
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->count($find);
    }

    public function countProviderUsers($providerId, $disableOnly = false)
    {
        $find = [
            'provider.' . $providerId . '.name' => [
                '$exists' => true,
            ],
        ];

        if ($disableOnly) {
            $find['$or'] = [
                ['$and' => [
                    ['provider.' . $providerId . '.exchangeInternalIds' => ['$exists'=> true]],
                    ['$or' => [
                        ['provider.' . $providerId . '.exchangeInternalIds.disconnected' => ['$exists' => false]],
                        ['provider.' . $providerId . '.exchangeInternalIds.disconnected' => ['$eq' => false]]
                    ]]
                ]],
                ['$and' => [
                    ['provider.' . $providerId . '.exchangeInternalIds' => ['$exists'=> false]],
                    ['provider.' . $providerId . '.disable' => false]
                ]]
            ];
        }

        return $this->mongoDBLink->selectCollection($this->collectionName)->count($find);
    }


    /**
     * Look for users with the exchange, identify by id, connected.
     *
     * @param string $exchangeId
     * @param UTCDateTime $olderThan
     * @param bool|string $userId
     * @return \MongoDB\Driver\Cursor
     */
    public function getUsersWithExchangeId(string $exchangeId, UTCDateTime $olderThan, $userId = false)
    {
        if ($userId) {
            $find = [
                '_id' => $this->parseMongoDBObject($userId),
            ];
        } else {
            $find = [
                'exchanges._id' => $this->parseMongoDBObject($exchangeId),
                'createdAt' => [
                    '$lt' => $olderThan
                ]
            ];
        }

        $options = [
            //'noCursorTimeout' => true,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }


    /**
     * Return the list of active users connected to service.
     *
     * @param string $providerId
     * @param bool $selectDisconnecting
     * @return array
     */
    public function getAllUsersIdConnectedToService(string $providerId, bool $selectDisconnecting = false)
    {
        $find = [
            'provider.' . $providerId . '._id' => new ObjectId($providerId),
        ];

        $options = [
            'projection' => [
                '_id' => 1,
                'provider.' . $providerId . '.exchangeInternalIds' => 1,
            ]
        ];

        $users = $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);

        $usersId = [];
        foreach ($users as $user) {
            if (empty($user->provider->$providerId->exchangeInternalIds)) {
                continue;
            }
            foreach ($user->provider->$providerId->exchangeInternalIds as $exchangeInternalId) {
                if (($selectDisconnecting || empty($exchangeInternalId->disconnecting)) && empty($exchangeInternalId->disconnected)) {
                    $usersId[] = $user->_id->__toString() . ':' . $exchangeInternalId->internalId;
                }
            }
        }

        return $usersId;
    }

    public function getAllUsers()
    {
        $find = [];
        $options = [
            //'noCursorTimeout' => true,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options);
    }

    public function getOlderLockedUsersForMoreThan($minutes, $lockedBy)
    {
        $timeLimit = (time() - $minutes * 60) * 1000;

        $find = [
            'locked' => true,
            '$or' => [
                [
                    'lockedBy' => [
                        '$ne' => $lockedBy
                    ],
                ],
                [
                    'lockedFrom' => [
                        '$ne' => gethostname()
                    ],
                ]
            ],
            'lockedAt' => [
                '$lt' => new UTCDateTime($timeLimit),
            ],

        ];

        $set = [
            '$set' => [
                'lockedBy' => $lockedBy,
                'lockedFrom' => gethostname(),
            ]
        ];

        $options = [
            'sort' => [
                'lockedAt' => 1,
            ],
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];


        return $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $set, $options);
    }

    /**
     * Update the given user with the given new set.
     *
     * @param ObjectId $userId
     * @param array $set
     * @return array|object|null|bool
     */
    public function findAndUpdateUser(ObjectId $userId, array $set)
    {
        if (empty($set['$set']) && empty($set['$pull']) && empty($set['$push'])) {
            $this->Monolog->sendEntry('warning', 'The given set does not contain $set, $pull or $push', $set);
            return false;
        }

        $find = [
            '_id' => $userId,
        ];

        $options = [
            'sort' => [
                'lockedAt' => 1,
            ],
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $set, $options);
    }

    /**
     * Find the user owner of an exchange from a key.
     * @param string $key
     * @return mixed
     */
    public function findUserFromExchangeSignalsKey(string $key)
    {
        $hash = md5($key);

        //We check for both, just in case they're not hashed yet
        $find = [
            '$or' => [
                ['exchanges.signalsKey' => $key],
                ['exchanges.signalsKey' => $hash]
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);
    }


    /**
     * Get the user from the given refCode.
     * @param string $refCode
     * @return array|object|null
     */
    public function getReferring(string $refCode)
    {
        $find = [
            'refCode' => $refCode,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);
    }

    public function getUserFromEmail($email)
    {
        $find = [
            'email' => strtolower($email)
        ];
        $user = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);

        return !isset($user->email) ? false : $user;
    }

    public function getUser($userId)
    {
        $find = [
            '_id' => $this->parseMongoDBObject($userId),
        ];
        $user = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);

        return !isset($user->email) ? false : $user;
    }

    /**
     * Return exchange connection data.
     * @param BSONDocument $user
     * @param string $internalId
     * @return array
     */
    public function getExchangeConnectionData(BSONDocument $user, string $internalId)
    {
        if (!empty($user->exchanges)) {
            foreach ($user->exchanges as $exchange) {
                if ($exchange->internalId === $internalId) {
                    $exchangeName = $exchange->name;
                    $exchangeType = empty($exchange->exchangeType) ? 'spot' : $exchange->exchangeType;
                    $testNet = !empty($exchange->isTestnet);
                    return [$exchangeName, $exchangeType, $testNet];
                }
            }

            return [false, false, false];
        }
    }

    /**
     * Return the user exchange if exists.
     *
     * @param ObjectId|string $userId
     * @param string $internalExchangeId
     * @return array|bool|object|null
     */
    public function getUserWithInternalExchange($userId, string $internalExchangeId)
    {
        $find = [
            '_id' => $this->parseMongoDBObject($userId),
            'exchanges.internalId' => $internalExchangeId
        ];

        $options = [
            'projection' => [
                '_id' => 1,
                'exchanges.$' => 1
            ]
        ];
        $user = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find, $options);

        return !isset($user->exchanges) ? false : $user;
    }

    public function findUserFromInternalExchangeId(string $internalExchangeId)
    {
        $find = [
            'exchanges.internalId' => $internalExchangeId
        ];

        $options = [];
        return $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find, $options);
    }

    public function getOlderUnlockedUserAndLockIt($sortField, $process, $userId = false, $minutesSinceLastCheck = false)
    {
        if ($userId) {
            $find = [
                '_id' => $this->parseMongoDBObject($userId),
            ];
        } else {
            $find = [
                'locked' => false,
            ];
        }

        $set = [
            '$set' => [
                'locked' => true,
                'lockedAt' => new UTCDateTime(),
                'lockedBy' => $process,
                'lockedFrom' => gethostname(),
                $sortField => new UTCDateTime(),
            ]
        ];

        if (!$userId && $minutesSinceLastCheck) {
            $find['$or'] = [
                [
                    $sortField => [
                        '$exists' => false,
                    ]
                ],
                [
                    $sortField => [
                        '$lte' => new UTCDateTime((time() - $minutesSinceLastCheck * 60) * 1000)
                    ],
                ]
            ];
        }

        $options = [
            'sort' => [
                $sortField => 1,
            ],
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];


        return $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $set, $options);
    }

    /**
     * @param string $subAccountId
     * @return object|null
     */
    public function getUserBySubAccountId(string $subAccountId): ?object
    {
        $find = [
            'exchanges.subAccountId' => $subAccountId
        ];

        $options = [];

        $user = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find, $options);
        if (empty($user->email)) {
            return null;
        }

        return $user;
    }

    /**
     * Get the next user for fetching the deposits.
     *
     * @param string $userId
     * @param string $processName
     * @return object
     */
    public function getUserByLastDepositsCheck(string $userId, string $processName): object
    {
        $find = [
            'exchanges' => [
                '$elemMatch' => [
                    'name' => 'Zignaly',
                    'subAccountId' => [
                        '$exists' => true,
                    ],
                ]
            ]
        ];

        if ($userId) {
            $find['_id'] = $this->parseMongoDBObject($userId);
        }

        $set = [
            '$set' => [
                'last'.ucfirst($processName) => new UTCDateTime(),
            ],
        ];

        $options = [
            'sort' => [
                'last'.ucfirst($processName) => 1,
            ],
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $set, $options);
    }

    public function getLastBalanceUpdatedUser($userId = false)
    {
        $find = [
            'locked' => false,
        ];

        if ($userId) {
            $find['_id'] = $this->parseMongoDBObject($userId);
            unset($find['locked']);
        }

        $set = [
            '$set' => [
                'lastUpdatedBalance' => new UTCDateTime(),
                'locked' => true,
                'lockedAt' => new UTCDateTime(),
                'lockedBy' => 'fetchBalance',
                'lockedFrom' => gethostname(),
            ],
        ];

        $options = [
            'sort' => [
                'lastUpdatedBalance' => 1,
            ],
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOneAndUpdate($find, $set, $options);
    }

    /**
     * Return the numbers of followers for a given service since the specific days.
     *
     * @param string $providerId
     * @param int $days
     * @return int
     */
    public function countUsersFollowingServiceSince(string $providerId, int $days)
    {
        $sinceDate = strtotime('-' . $days . ' days');

        $find = [
            'provider.' . $providerId . '.createdAt' => [
                '$gte' => new UTCDateTime($sinceDate * 1000),
            ],
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->count($find);
    }

    /**
     * Return the list of users following the given provider.
     *
     * @param string $providerId
     * @param bool $checkDisabled
     *
     * @return \MongoDB\Driver\Cursor
     */
    public function getUsersFollowingProvider(string $providerId, bool $checkDisabled = false)
    {
        $find = [
            'provider.' . $providerId => [
                '$exists' => true,
            ],
        ];
        if ($checkDisabled) {
            $find['$or'] = [
                ['$and' => [
                    ['provider.' . $providerId . '.exchangeInternalIds' => ['$exists'=> true]],
                    ['$or' => [
                        ['provider.' . $providerId . '.exchangeInternalIds.disconnected' => ['$exists' => false]],
                        ['provider.' . $providerId . '.exchangeInternalIds.disconnected' => ['$eq' => false]]
                    ]]
                ]],
                ['$and' => [
                    ['provider.' . $providerId . '.exchangeInternalIds' => ['$exists'=> false]],
                    ['provider.' . $providerId . '.disable' => false]
                ]]
            ];
        }

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find);
    }

    /**
     * Return the list of users subscribed to the given provider posts.
     *
     * @param string $providerId
     * @param bool $checkDisabled
     *
     * @return \MongoDB\Driver\Cursor
     */
    public function getUsersSubscribedToProviderPosts(string $providerId)
    {
        $find = [
            "provider.$providerId.notificationsPosts" => true,
            "provider.$providerId.disable" => false,
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find);
    }

    /*public function setInvalidKeys($userId, $exchangeId)
    {
        $userId = !is_object($userId) ? new ObjectId($userId) : $userId;

        $find = [
            '_id' => $userId,
        ];

        $set = [
            '$set' => [
                'exchange.' . $exchangeId . '.areKeysValid' => false,
            ],
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->updateOne($find, $set)
            ->getModifiedCount() == 1;
    }*/

    public function unlockUser($userId, $dateField = false, $flag = false)
    {
        $find = [
            '_id' => $this->parseMongoDBObject($userId),
        ];

        $set = [
            '$set' => [
                'locked' => false,
            ],
        ];

        if ($dateField)
            $set['$set'][$dateField] = new UTCDateTime();

        if ($flag)
            $set['$set'][$flag] = false;

        return $this->mongoDBLink->selectCollection($this->collectionName)->updateOne($find, $set)->getModifiedCount();
    }

    /**
     * Update balanceSynced parameter for a given internal exchange.
     *
     * @param ObjectId $userId
     * @param string $internalExchangeId
     * @param bool $status
     * @return bool
     */
    public function updateInternalExchangeBalanceSynced(ObjectId $userId, string $internalExchangeId, bool $status)
    {
        $find = [
            '_id' => $userId,
            'exchanges.internalId' => $internalExchangeId
        ];

        $set = [
            '$set' => [
                'exchanges.$.balanceSynced' => $status,
                'exchanges.$.balanceSyncedAt' => new UTCDateTime(),
            ],
        ];

        return $this->mongoDBLink->selectCollection('user')->updateOne($find, $set)->getModifiedCount() == 1;
    }

    /**
     * Generate the exchange signals key for the given user and exchange.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @return string|null
     */
    public function updateUserSignalsKey( string $userId, string $internalExchangeId): ?string
    {
        $key = ''; //Todo

        $hash = md5($key);
        $encrypted = $this->Security->encrypt($key);

        $find = [
            '_id' => new ObjectId($userId),
            'exchanges.internalId' => $internalExchangeId
        ];

        $set = [
            '$set' => [
                'exchanges.$.signalsKey' => $hash,
                'exchanges.$.signalsKeyEncrypted' => $encrypted,
            ],
        ];

        if (1 === $this->mongoDBLink->selectCollection('user')->updateOne($find, $set)->getModifiedCount()) {
            return $key;
        } else {
            return null;
        }
    }

    public function updateKeysStatusForExchange($userId, $internalExchangeId, $keysStatus, $checkAuthCount)
    {
        return true;
        /*$userId = !is_object($userId) ? new ObjectId($userId) : $userId;

        $find = [
            '_id' => $userId,
            'exchanges.internalId' => $internalExchangeId
        ];

        $set = [
            '$set' => [
                'exchanges.$.checkAuthCount' => $checkAuthCount,
            ],
        ];

        if ($keysStatus !== null) {
            $set['$set']['exchanges.$.areKeysValid'] = $keysStatus;
        }

        return $this->mongoDBLink->selectCollection('user')->updateOne($find, $set)->getModifiedCount() == 1;*/
    }

    /**
     * Update massively users retain parameter.
     *
     * @param $updates
     * @return int
     */
    public function bulkUpdate($updates)
    {
        return $this->mongoDBLink->selectCollection('user')->bulkWrite($updates)->getMatchedCount();
    }

    public function rawUpdate($find, $set)
    {
        return $this->mongoDBLink->selectCollection($this->collectionName)->updateOne($find, $set)->getMatchedCount();
    }

    public function updateUser($userId, $settings, $push = false)
    {
        $userId = !is_object($userId) ? new ObjectId($userId) : $userId;

        $find = [
            '_id' => $userId,
        ];

        $set = [
            '$set' => $settings,
        ];

        if ($push) {
            $set['$push'] = $push;
        }

        $user = $this->mongoDBLink->selectCollection('user')->updateOne($find, $set);

        return $user->getModifiedCount() == 1 ? true : false;
    }

    private function parseMongoDBObject($element)
    {
        return is_object($element) ? $element : new ObjectId($element);
    }
}
