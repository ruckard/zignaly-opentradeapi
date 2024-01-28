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


class Exchange
{
    private $mongoDBLink;
    private $collectionName = 'exchange';

    function __construct()
    {
        global $mongoDBLink;
        $this->mongoDBLink = $mongoDBLink;
    }

    public function getExchangeByName($name)
    {
        $find = [
            'name' => new \MongoDB\BSON\Regex($name, 'i'),
        ];

        $exchange = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);

        return isset($exchange->name) ? $exchange : false;
    }

    public function getExchangeIdFromName($name)
    {
        $find = [
            'name' => new \MongoDB\BSON\Regex($name, 'i'),
        ];
        $exchange = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);

        return isset($exchange->name) && strtolower($exchange->name) == strtolower($name) ? $exchange->_id->__toString() : false;
    }

    /**
     * Look for an exchange entry with the given ID.
     * @param string $id
     * @return array|object|null
     */
    public function getExchange(string $id)
    {
        $find = [
            '_id' => new \MongoDB\BSON\ObjectId($id)
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);
    }

    public function getExchanges()
    {
        $find = [];

        return $this->mongoDBLink->selectCollection($this->collectionName)->find($find);
    }

    public function getLogMethodFromError($error)
    {
        $error = strtolower($error);

        $knownErrors = [
            ['msg' => 'Order does not exist', 'method' => 'debug'],
            ['msg' => 'Timestamp for this request is outside of the recvWindow', 'method' => 'debug'],
            ['msg' => 'Market is closed', 'method' => 'debug'],
            ['msg' => 'API trading is not enabled', 'method' => 'debug'],
            ['msg' => 'Not valid order status from ccxt expired []', 'method' => 'error'],
            ['msg' => 'Order does not exist', 'method' => 'warning'],
            ['msg' => 'Account has insufficient balance for requested action.', 'method' => 'warning'],
            ['msg' => 'Balance insufficient', 'method' => 'warning'],
            ['msg' => 'Invalid quantity.', 'method' => 'warning'],
            ['msg' => 'Filter failure: MIN_NOTIONAL', 'method' => 'warning'],
            ['msg' => 'Invalid API-key, IP, or permissions for action', 'method' => 'warning'],
            ['msg' => 'Invalid API key/secret pair', 'method' => 'warning'],
            ['msg' => 'Margin is insufficient', 'method' => 'warning'],
        ];

        foreach ($knownErrors as $knownError)
            if (strpos($error, strtolower($knownError['msg'])) !== false)
                return $knownError['method'];

        return 'critical';
    }

    /**
     * Check if exchange exists in database and is enabled
     *
     * @param string $exchangeName exchange name
     * @return boolean
     */
    public function checkIfExchangeIsSupported(string $exchangeName): bool
    {
        $exchange = $this->getExchangeByName($exchangeName);
        return !empty($exchange) && (!empty($exchange->enabled) || !empty($exchange->enabledInTest));
    }
}