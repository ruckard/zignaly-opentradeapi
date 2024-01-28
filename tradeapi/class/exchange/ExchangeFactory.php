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


namespace Zignaly\exchange;

use MongoDB\Model\BSONDocument;

class ExchangeFactory
{
    /**
     * Undocumented function
     *
     * @param string $id
     * @param ExchangeOptions $exchangeOptions
     *
     * @return BaseExchange
     */
    public static function newInstance(string $id, ExchangeOptions $exchangeOptions)
    {
        $class = 'Zignaly\\exchange\\ccxtwrap\\exchanges\\'.\ucfirst($id);

        return new $class($exchangeOptions);
    }

    /**
     * Create an exchange instance for a given exchange name and type.
     *
     * @param string $exchangeName Exchange name.
     * @param string $exchangeType Exchange type.
     * @param array|null $exchangeOptions Exchange options.
     *
     * @return \Zignaly\exchange\BaseExchange
     */
    public static function createFromNameAndType(
        string $exchangeName,
        string $exchangeType,
        array $exchangeOptions = null
    ): BaseExchange
    {
        $exchangeClassName = self::exchangeNameResolution($exchangeName, $exchangeType);

        return self::newInstance($exchangeClassName, new ExchangeOptions($exchangeOptions ?? []));
    }

    /**
     * Create an exchange instance from a user exchange connection object.
     *
     * @param \MongoDB\Model\BSONDocument $exchangeConnection User exchange connection object.
     * @param array $options Exchange CCXT instance construct options.
     *
     * @return \Zignaly\exchange\BaseExchange
     */
    public static function createFromUserExchangeConnection(BSONDocument $exchangeConnection, array $options)
    {
        // Backward compatibility for old exchange connections that don't have exchangeName property.
        $exchangeName = isset($exchangeConnection->exchangeName) ? $exchangeConnection->exchangeName : $exchangeConnection->name;
        $exchangeType = 'spot';
        if (isset($exchangeConnection->exchangeType)) {
            $exchangeType = $exchangeConnection->exchangeType;
        }

        return self::createFromNameAndType($exchangeName, $exchangeType, $options);
    }

    /**
     * Translate incoming exchange to interal mapping for CCXT
     *
     * Binance, spot           -> binance
     * Binance, futures        -> binancefutures
     * BinanceFutures, spot    -> binancefutures
     * BinanceFutures, futures -> binancefutures
     * KuCoin, spot            -> kucoin
     * KuCoin, futures         -> kucoin
     * Zignaly, spot           -> binance
     * Zignaly, futures        -> binancefutures
     *
     * @param string $exchangeName
     * @param string $accountType
     *
     * @return string
     */
    public static function exchangeNameResolution($exchangeName, $accountType = 'spot')
    {
        $exchangeName = strtolower($exchangeName);
        if (!$exchangeName || $exchangeName == '' || $exchangeName == null) {
            $exchangeName = 'binance'; //Todo: fix this, it has to come from outside.
        }

        if ($exchangeName == 'zignaly') {
            $exchangeName = 'binance';
        }

        $accountType = strtolower($accountType);
        if ($exchangeName == 'binance') {
            if ($accountType == 'futures') {
                $exchangeName = 'binancefutures';
            }
        }

        return $exchangeName;
    }

}