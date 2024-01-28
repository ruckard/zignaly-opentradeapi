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


class GlobalBlackList
{
    private $mongoDBLink;
    private $Monolog;

    function __construct()
    {
        global $mongoDBLink, $Monolog;

        $this->mongoDBLink = $mongoDBLink;
        $this->Monolog = $Monolog;
    }

    public function checkIfCoinsAreListed($exchange, $quote, $base)
    {
        $find = [
            'exchange' => $exchange,
            '$or' => [
                ['coin' => $quote],
                ['coin' => $base],
            ]
        ];

        $coin = $this->mongoDBLink->selectCollection('globalBlackList')->findOne($find);

        return isset($coin->coin);
    }
}