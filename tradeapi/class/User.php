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
use MongoDB\Model\BSONDocument;
use Zignaly\exchange\ZignalyExchangeMapping;

class User
{
    /** @var \MongoDB\Database  */
    private $mongoDBLink;
    /** @var \MongoDB\Database  */
    private $mongoDBLinkRO;
    private $Monolog;

    function __construct()
    {
        global $mongoDBLink;

        $this->mongoDBLink = $mongoDBLink;
    }

    public function configureMongoDBLinkRO()
    {
        global $mongoDBLinkRO;

        $this->mongoDBLinkRO = $mongoDBLinkRO;
    }

    public function configureLogging($Monolog)
    {
        $this->Monolog = $Monolog;
    }

    public function getAll($projectId = false)
    {
        $find = [];
        if ($projectId)
            $find = [
                'projectId' => $projectId,
            ];

        $options = [
            //'noCursorTimeout' => true,
        ];

        return $this->mongoDBLink->selectCollection('user')->find($find, $options);
    }

    public function getUser($userId)
    {
        $userId = !is_object($userId) ? new ObjectId($userId) : $userId;

        $find = [
            '_id' => $userId
        ];
        $user = $this->mongoDBLink->selectCollection('user')->findOne($find);

        return !isset($user->email) ? false : $user;
    }

    public function getUserByEmail($email)
    {
        $find = [
            'email' => $email
        ];
        $user = $this->mongoDBLink->selectCollection('user')->findOne($find);

        return !isset($user->email) ? false : $user;
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

        if ($push)
            $set['$push'] = $push;

        $user = $this->mongoDBLink->selectCollection('user')->updateOne($find, $set);

        return $user->getModifiedCount() == 1 ? true : false;
    }

    public function updateUserProvider($userId, $settings, $push = false)
    {
        $find = [
            '_id' => $userId,
        ];

        $set = [
            '$set' => $settings,
        ];

        if ($push)
            $set['$push'] = $push;

        $user = $this->mongoDBLink->selectCollection('user')->updateOne($find, $set);

        return $user->getModifiedCount() == 1 ? true : false;
    }

    public function checkIfProviderParamIsActivate($userId, $providerId, $param)
    {
        $userId = is_object($userId) ? $userId : new ObjectId($userId);
        $providerId = $providerId == "1" || !is_object($providerId) ? $providerId : $providerId->__toString();

        if ($providerId == 1)
            return true;

        $find = [
            '_id' => $userId,
            'provider.' . $providerId . '.' . $param => true,
        ];

        $user = $this->mongoDBLink->selectCollection('user')->findOne($find);

        return isset($user->provider->$providerId) && !$user->provider->$providerId->disable;
    }

    public function checkIfUserAcceptUpdatingSignalForProvider($userId, $providerId)
    {
        $userId = is_object($userId) ? $userId : new ObjectId($userId);
        $providerId = $providerId == "1" || !is_object($providerId) ? $providerId : $providerId->__toString();

        if ($providerId == 1)
            return true;

        $find = [
            '_id' => $userId,
            'provider.' . $providerId . '.acceptUpdateSignal' => true,
        ];

        $user = $this->mongoDBLink->selectCollection('user')->findOne($find);

        return isset($user->provider->$providerId->acceptUpdateSignal);
    }

    public function checkIfUserAcceptReBuySignalsForThisProvider($userId, $providerId)
    {
        $userId = is_object($userId) ? $userId : new ObjectId($userId);
        $providerId = $providerId == "1" || !is_object($providerId) ? $providerId : $providerId->__toString();

        $find = [
            '_id' => $userId,
            'provider.' . $providerId . '.reBuyFromProvider.limitReBuys' => [
                '$exists' => true,
            ],
        ];

        $user = $this->mongoDBLink->selectCollection('user')->findOne($find);

        return (isset($user->provider->$providerId) && !$user->provider->$providerId->disable) ?
            [
                $user->provider->$providerId->reBuyFromProvider->quantityPercentage,
                $user->provider->$providerId->reBuyFromProvider->limitReBuys
            ] : [false, false,];
    }

    /**
     * Get user connected exchange by the connection ID.
     *
     * @param \MongoDB\Model\BSONDocument $user A user document.
     * @param string $exchangeInternalId Exchange internal ID (User / Exchange connection ID).
     *
     * @return bool|\MongoDB\Model\BSONDocument
     */
    public function getConnectedExchangeById(BSONDocument $user, string $exchangeInternalId)
    {
        if (!$user->exchanges) {
            return false;
        }

        foreach ($user->exchanges as $userExchange) {
            if ($userExchange->internalId == $exchangeInternalId) {
                return $userExchange;
            }
        }

        return false;
    }

    public function getExchangeSettings($user, $exchange, $provider)
    {
        global $Monolog;

        $exchangeId = empty($exchange['_id']) ? false : $exchange['_id'];

        if (empty($exchangeId) || !$provider || !isset($user->_id)) {
            return false;
        }

        $providerId = $this->getProviderIdString($provider);

        $isCopyTrading = isset($provider['isCopyTrading']) ? $provider['isCopyTrading'] : false;

        $exchangeInternalId = empty($exchange['internalId']) ? false : $exchange['internalId'];
        //$Monolog->sendEntry('debug', "Exchange Internal ID: $exchangeInternalId");
        if (empty($exchangeInternalId)) {
            return false;
        }

        if (empty($user->exchanges)) {
            return false;
        }

        foreach ($user->exchanges as $userExchange) {
            if ($userExchange->internalId == $exchangeInternalId) {
                $globalExchange = $userExchange;
            }
        }

        if (!isset($globalExchange)) {
            return false;
        }

        if (isset($globalExchange->key)) {
            unset($globalExchange->key);
        }
        if (isset($globalExchange->secret)) {
            unset($globalExchange->secret);
        }
        if (isset($globalExchange->password)) {
            unset($globalExchange->password);
        }
        if ($providerId == 1 || $isCopyTrading) {
            return $globalExchange;
        }

        if (!empty($user->provider->$providerId->exchanges)) {
            foreach ($user->provider->$providerId->exchanges as $userProviderExchange) {
                if ($userProviderExchange->internalId == $exchangeInternalId) {
                    $providerExchange = $userProviderExchange;
                }
            }
        }

        if (empty($providerExchange)) {
            return false;
        }

        $providerExchange->areKeysValid = isset($globalExchange->areKeysValid) ? $globalExchange->areKeysValid : false;
        $providerExchange->globalMaxPositions = isset($globalExchange->globalMaxPositions) ? $globalExchange->globalMaxPositions : false;
        $providerExchange->globalMinVolume = isset($globalExchange->globalMinVolume) ? $globalExchange->globalMinVolume : false;
        $providerExchange->globalPositionsPerMarket = isset($globalExchange->globalPositionsPerMarket) ? $globalExchange->globalPositionsPerMarket : false;
        $providerExchange->globalBlacklist = isset($globalExchange->globalBlacklist) ? $globalExchange->globalBlacklist : false;
        $providerExchange->globalWhitelist = isset($globalExchange->globalWhitelist) ? $globalExchange->globalWhitelist : false;
        $providerExchange->globalDelisting = isset($globalExchange->globalDelisting) ? $globalExchange->globalDelisting : false;
        $providerExchange->exchangeType = isset($globalExchange->exchangeType) ? $globalExchange->exchangeType : 'spot';

        // add paperTrading
        if (!empty($globalExchange->paperTrading)) {
            $providerExchange->paperTrading = true;
        }
        // add testNet
        $providerExchange->isTestnet = !empty($globalExchange->isTestnet);

        //$Monolog->sendEntry('debug', "Sending back exchange");
        return $providerExchange;
    }

    private function getProviderIdString($provider)
    {
        if (isset($provider->_id)) {
            $providerId = $provider->_id;
        } else if (isset($provider['_id'])) {
            $providerId = $provider['_id'];
        } else {
            return false;
        }

        if (is_object($providerId))
            $providerId = $providerId->__toString();

        return $providerId;
    }

    private function checkIfExchangeFromProviderIsEnable($user, $provider, $exchangeId)
    {
        $providerId = $this->getProviderIdString($provider);

        $model = new ZignalyExchangeMapping($this->mongoDBLink);
        $searchExchangeList = $model->getSubExchanges($exchangeId);
        $searchExchangeList[] = $exchangeId;

        if (!$providerId)
            return false;

        if (!isset($user->provider->$providerId->exchanges))
            return false;

        foreach ($user->provider->$providerId->exchanges as $exchange)
            if (in_array($exchange->_id, $searchExchangeList))
                return true;

        if (empty($user->exchanges)) {
            return false;
        }

        if (isset($user->provider->$providerId->exchangeInternalId))
            foreach ($user->exchanges as $exchange)
                if ($exchange->internalId == $user->provider->$providerId->exchangeInternalId && $exchange->_id == $exchangeId)
                    return true;

        return false;
    }

    public function getUsersSubscribeToSignal($signal, $exchangeId, $providerId)
    {
        $model = new ZignalyExchangeMapping($this->mongoDBLink);
        $searchExchangeList = $model->getSubExchangesAsStringList($exchangeId);
        $searchExchangeList[] = $exchangeId;

        if ($signal['origin'] == 'manualBuy' || $signal['origin'] == 'manual')
            $find = [
                '_id' => new ObjectId($signal['userId']),
            ];
        else
            $find = [
                'provider.' . $providerId . '.disable' => false,
                'disable' => false,
            ];

        return $this->mongoDBLinkRO->selectCollection('user')->find($find);
    }

    public function updateKeysStatusForExchange($userId, $internalExchangeId, $keysStatus)
    {
        $userId = !is_object($userId) ? new ObjectId($userId) : $userId;

        $find = [
            '_id' => $userId,
            'exchanges.internalId' => $internalExchangeId
        ];

        $set = [
            '$set' => [
                'exchanges.$.areKeysValid' => $keysStatus
            ],
        ];

        return $this->mongoDBLink->selectCollection('user')->updateOne($find, $set)->getModifiedCount() == 1;
    }
}
