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

class ZignalyExchangeCodes {
    const ZignalyBinance = 'Binance';
    const ZignalyBitmex  = 'BitMEX';
    const ZignalyKucoin  = 'KuCoin';
    const ZignalyBinanceFutures = "BinanceFutures";
    const ZignalyZignalyBinance = "Zignaly";
    const ZignalyZignalyBinanceFutures = "ZignalyFutures";
    const ZignalyBittrex = "Bittrex";
    const ZignalyVcce = "VCCE";
    const ZignalyAscendex = "AscendEX";

    const CcxtBinance = 'binance';
    const CcxtBitmex  = 'bitmex';
    const CcxtKucoin  = 'kucoin';
    const CcxtVcce    = 'vcc';
    const CcxtAscedex = 'ascendex';

    /** @var array<string,string> */
    public static $toCcxt = array(
        self::ZignalyBinance => self::CcxtBinance,
        self::ZignalyBitmex  => self::CcxtBitmex,
        self::ZignalyKucoin  => self::CcxtKucoin,
        self::ZignalyBinanceFutures => self::CcxtBinance,
        self::ZignalyZignalyBinance => self::CcxtBinance,
        self::ZignalyZignalyBinanceFutures => self::CcxtBinance,
        self::ZignalyVcce => self::CcxtVcce,
        self::ZignalyAscendex => self::CcxtAscedex,
        
    );
    /** @var array<string,string> */
    public static $toZignaly = array(
        self::CcxtBinance => self::ZignalyBinance,
        self::CcxtBitmex  => self::ZignalyBitmex,
        self::CcxtKucoin  => self::ZignalyKucoin,
        self::CcxtVcce => self::ZignalyVcce,
        self::CcxtAscedex => self::ZignalyAscendex,
    );

    public static $zignalyAliasForTradesPrices = array(
        self::ZignalyBinance => self::ZignalyBinance,
        self::ZignalyBitmex  => self::ZignalyBitmex,
        self::ZignalyKucoin  => self::ZignalyKucoin,
        self::ZignalyBinanceFutures => self::ZignalyBinanceFutures,
        self::ZignalyZignalyBinance => self::ZignalyBinance,
        self::ZignalyZignalyBinanceFutures => self::ZignalyBinanceFutures,
        self::ZignalyVcce => self::ZignalyVcce,
        self::ZignalyAscendex => self::ZignalyAscendex,

    );
    /**
     * Convert to ccxt from zignaly
     *
     * @param string $id exchange name in zignaly
     * 
     * @return string
     */
    public static function toCcxtId($id)
    {
        if (array_key_exists($id, self::$toCcxt)) {
            return self::$toCcxt[$id];
        }

        return '';
    }
    /**
     * Convert exchange name frome to zignaly from ccxt
     *
     * @param string $id ccxt exchange id
     * 
     * @return string
     */
    public static function toZignalyId($id)
    {
        if (array_key_exists($id, self::$toZignaly)) {
            return self::$toZignaly[$id];
        }

        return '';
    }
    /**
     * Check if is valid zignaly name
     *
     * @param string $exchange exchange name
     * 
     * @return boolean
     */
    public static function isValidZignalyExchange($exchange)
    {
        return array_key_exists($exchange, self::$toCcxt);
    }
    /**
     * Check if is valid ccxt name
     *
     * @param string $exchange exchnge name
     * 
     * @return boolean
     */
    public static function isValidCcxtExchange($exchange)
    {
        return array_key_exists($exchange, self::$toZignaly);
    }

    /**
     * Get real zignaly name when using aliases like Zignaly exchange
     *
     * @param string $exchange exchange name
     * 
     * @return void
     */
    public static function getRealExchangeName($exchange) {
        if (!is_string($exchange)) {
            return '';
        }

        if (array_key_exists($exchange, self::$zignalyAliasForTradesPrices)) {
            return self::$zignalyAliasForTradesPrices[$exchange];
        }

        return '';
    }
    /**
     * Get exchange name from exchange case insensitive
     *
     * @param string $exchange exchange name
     * 
     * @return string|false
     */
    public static function getExchangeFromCaseInsensitiveString($exchange)
    {
        foreach (array_keys(self::$toCcxt) as $validExchange) {
            $upperExchange = strtoupper($validExchange);
            if ($upperExchange == strtoupper($exchange)) {
                return $validExchange;
            }
        }
        return false;
    }

    /**
     * Get exchange name with type from case insensitive
     *
     * @param string      $exchange exchange name
     * @param string|bool $type     type "futures"|"spot"|"margin"
     * 
     * @return string|false
     */
    public static function getExchangeFromCaseInsensitiveStringWithType(
        $exchange, $type
    ) {
        if ((strtoupper($exchange) == strtoupper(self::ZignalyBinance)) ||
            (strtoupper($exchange) == strtoupper(self::ZignalyZignalyBinance))
        ) {
            if (strtoupper($type) == 'FUTURES') {
                $exchange .= 'FUTURES';
            }
        }

        foreach (array_keys(self::$zignalyAliasForTradesPrices) as $validExchange) {
            $upperExchange = strtoupper($validExchange);
            if ($upperExchange == strtoupper($exchange)) {
                return $validExchange;
            }
        }
        return false;
    }
    /**
     * Check if exchange name is expected
     *
     * @param string $exchangeName     exchange name case insensitive
     * @param string $expectedExchange expected exchange name
     * 
     * @return boolean
     */
    public static function is($exchangeName, $expectedExchange)
    {
        return $expectedExchange === ZignalyExchangeCodes::
            getExchangeFromCaseInsensitiveString($exchangeName);
    }
    /**
     * Check if exchange name is BitMEX
     *
     * @param string $exchange exchange name
     * 
     * @return boolean
     */
    public static function isBitmex($exchange)
    {
        return self::is($exchange, ZignalyExchangeCodes::ZignalyBitmex);
    }
    /**
     * Check if exchange name is Binance
     *
     * @param string $exchange exchange name
     * 
     * @return boolean
     */
    public static function isBinance($exchange)
    {
        return self::is($exchange, ZignalyExchangeCodes::ZignalyBinance);
    }
    /**
     * Check if exchange name is BitMEX
     *
     * @param string $exchange exchange name
     * 
     * @return boolean
     */
    public static function isKuCoin($exchange)
    {
        return self::is($exchange, ZignalyExchangeCodes::ZignalyKucoin);
    }

    public static function getExchangeNameAndTypeFromZignalyExchangeCodes($code)
    {
        switch($code) {
            case self::ZignalyBinance:
                return [self::ZignalyBinance, 'spot'];
            case self::ZignalyBitmex:
                return [self::ZignalyBitmex, 'futures'];
            case self::ZignalyKucoin:
                return [self::ZignalyKucoin, 'spot'];
            case self::ZignalyBinanceFutures:
                return [self::ZignalyBinance, 'futures'];
            case self::ZignalyVcce:
                return [self::ZignalyVcce, 'spot'];
            case self::ZignalyAscendex:
                return [self::ZignalyAscendex, 'spot'];
            default:
                throw new \Exception("Invalid exchange name {$code}");
        }
    }
    
}