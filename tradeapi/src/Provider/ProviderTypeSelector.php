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


namespace Zignaly\Provider;

/**
 * Class ProviderType
 * @package Zignaly\Provider
 */
class ProviderTypeSelector
{
    const PROVIDER_SIGNALPROV    = 'signal';
    const PROVIDER_COPYTRADER    = 'copytraders';

    const BITWISE_SIGNALPROV    = 0x01;
    const BITWISE_COPYTRADER    = 0x02;

    const VALID_PROVIDERS = [
        self::PROVIDER_SIGNALPROV,
        self::PROVIDER_COPYTRADER,
    ];

    const PROVIDER2BITWISE = [
        self::PROVIDER_SIGNALPROV    => self::BITWISE_SIGNALPROV,
        self::PROVIDER_COPYTRADER    => self::BITWISE_COPYTRADER,
    ];

    const BITWISE2PROVIDER = [
        self::BITWISE_SIGNALPROV    => self::PROVIDER_SIGNALPROV,
        self::BITWISE_COPYTRADER    => self::PROVIDER_COPYTRADER,
    ];

    /**
     * provider type selector bitwise
     *
     * @var integer
     */
    public $provTypeBitwise = 0;
    /**
     * Provider type selector string array
     *
     * @var string[]
     */
    public $provTypeArray = [];

    /**
     * ProviderTypeSelector constructor
     *
     * @param integer $bitwise
     */
    public function __construct($bitwise = 0)
    {
        $this->provTypeBitwise = $bitwise;
        $this->provTypeArray = self::fromBitWise($bitwise);
    }

    /**
     * Generate string array from bitwise
     *
     * @param int $bitwise
     * 
     * @return string[]
     */
    public static function fromBitWise(int $bitwise)
    {
        $ret = [];
        foreach (self::PROVIDER2BITWISE as $type => $value) {
            if ($bitwise & $value) {
                $ret[] = $type;
            }
        }
        return $ret;
    }

    /**
     * Generate bitwise codification from string array
     *
     * @param string[] $array
     * 
     * @return integer
     */
    public static function fromArray($array): int
    {
        $bitwise = 0;
        foreach ($array as $value) {
            if (isset(self::PROVIDER2BITWISE[$value])) {
                $bitwise |= self::PROVIDER2BITWISE[$value];
            }
        }
        return $bitwise;
    }

    /**
     * Create ProviderTypeSelector from payload string, encoded with concatenation 
     * of valid providers separated by a pipe char
     *
     * @param string $payload  concatenation of valid providers separated by a pipe char
     * @param string $property property name
     * 
     * @return ProviderTypeSelector
     */
    public static function fromPayload($payload, $property): ProviderTypeSelector
    {
        $array = [];
        if (isset($payload[$property])) {
            if (!is_array($payload[$property])) {
                $array = explode('|', $payload[$property]);
            } else {
                $array = $payload[$property];
            }
            $array = array_filter(
                filter_var_array($array, FILTER_SANITIZE_STRING),
                function ($a) {
                    return in_array($a, self::VALID_PROVIDERS);
                }
            );
        }
        return new ProviderTypeSelector(self::fromArray($array));
    }

    /**
     * Get bitwise type from mongo provider
     *
     * @param object $mongoProvider
     * @return int
     */
    public static function getMongoProviderType($mongoProvider)
    {
            return self::BITWISE_SIGNALPROV;

    }

    /**
     * Get string type from mongo provider
     *
     * @param object $mongoProvider
     * @return string
     */
    public static function getMongoProviderTypeString($mongoProvider)
    {
        return self::BITWISE2PROVIDER[self::getMongoProviderType($mongoProvider)];
    }

    /**
     * Is mongo provider selectable
     *
     * @param object $mongoProvider
     * @return boolean
     */
    public function isMongoProviderSelectable($mongoProvider)
    {
        return self::getMongoProviderType($mongoProvider) & $this->provTypeBitwise;
    }

    /**
     * Filter mongo provider list result for this selector
     *
     * @param object[] $providerList
     * @return object[]
     */
    public function filterMongoProviderResult($providerList)
    {
        $ret = [];
        foreach ($providers as $provider) {
            if (self::getMongoProviderType($provider) & $this->provTypeBitwise) {
                $ret[] = $provider;
            }
        }

        return $ret;
    }
    /**
     * Generate filter condition to select by type
     * 
     * @todo Better to add a string|code property in the provider and filter by it
     *
     * @return void
     */
    public function getMongoFind()
    {
        // all of them
        if ($this->provTypeBitwise
            & self::BITWISE_SIGNALPROV
            & self::BITWISE_COPYTRADER
        ) {
            return [];
        }
        // others than signal providers
        if ($this->provTypeBitwise
            & (~self::BITWISE_SIGNALPROV)
            & self::BITWISE_COPYTRADER
        ) {
            return ['isCopyTrading' => true];
        }
        // only signal providers
        if ($this->provTypeBitwise
            & self::BITWISE_SIGNALPROV
            & (~self::BITWISE_COPYTRADER)
        ) {
            return [
                '$or' => [
                    ['isCopyTrading' => ['$exists' => false]],
                    ['isCopyTrading' => false],
                ]
            ];
        }

        // else or
        $or = [];

        if ($this->provTypeBitwise & self::BITWISE_SIGNALPROV) {
            $or[] = [
                '$or' => [
                    ['isCopyTrading' => ['$exists' => false]],
                    ['isCopyTrading' => false],
                ]
            ];
        }

        if ($this->provTypeBitwise & self::BITWISE_COPYTRADER) {
            $or[] = [
                'isCopyTrading' => true,

            ];
        }


        
        if (count($or) > 1) {
            return ['$or' => $or];
        } else if (count($or) == 1) {
            return $or[0];
        } else {
            return [];
        }
    }
}