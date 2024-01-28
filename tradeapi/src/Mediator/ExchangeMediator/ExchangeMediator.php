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

namespace Zignaly\Mediator\ExchangeMediator;

use Exchange;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\marketencoding\MarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\ExchangeHandler\ExchangeHandler;
use Zignaly\Mediator\PositionHandler\PositionHandler;

/**
 * Get exchange data from a mongodb exchange record
 */
class ExchangeMediator
{
    /**
     * Create mediator from exchange record in mongo database
     *
     * @param \MongoDB\Model\BSONDocument $exchange mongodb exchange record
     * @param \MongoDB\Model\BSONDocument $psExchangeData profit sharing exchange data
     *
     * @return ExchangeMediator
     */
    public static function fromMongoExchange($exchange, $psExchangeData = null)
    {
        $exchangeName = $psExchangeData->name ?? $exchange->name;

        if (ZignalyExchangeCodes::isBitmex($exchangeName)) {
            return new BitmexExchangeMediator($exchange, $psExchangeData);
        } else {
            return new ExchangeMediator($exchange, $psExchangeData);
        }
    }
    /**
     * @var \MongoDB\Model\BSONDocument mongodb exchange record
     */
    protected $exchangeEntity;
    /**
     * @var \MongoDB\Model\BSONDocument profit sharing exchange data
     */
    protected $psExchangeData;
    /**
     * Market encoder
     *
     * @var MarketEncoder
     */
    protected $marketEncoder;
    /**
     * Constructor
     *
     * @param Object $exchange mongo db exchange record
     */
    public function __construct(Object $exchange, $psExchangeData = null)
    {
        if (!is_object($exchange)) {
            throw new \Exception(
                sprintf(
                    "Exchange doesn't contain a valid exchange object: %s",
                    json_encode($exchange, JSON_PRETTY_PRINT)
                )
            );
        }

        if (isset($psExchangeData->name)) {
            $exchangeName = $psExchangeData->name;
        } else {
            $exchangeName = $exchange->name;
        }

        if (!$exchangeName) {
            throw new \Exception(
                sprintf(
                    "Position exchange name is invalid: %s",
                    json_encode($exchange, JSON_PRETTY_PRINT)
                )
            );
        }
        $this->exchangeEntity = $exchange;
        $this->psExchangeData = $psExchangeData;
        $this->marketEncoder = BaseMarketEncoder::newInstance(
            $this->getName(),
            $this->getExchangeType()
        );
    }
    /**
     * Get exchange type 
     *
     * @return string
     */
    public function getExchangeType() : string
    {
        if (isset($this->psExchangeData->exchangeType)) {
            return strtolower(self::_getExchangeType($this->psExchangeData->exchangeType));
        }
        
        return self::_getExchangeType(
            isset($this->exchangeEntity->exchangeType) ? 
                $this->exchangeEntity->exchangeType : 'spot'
        );
    }
    /**
     * Is testnet exchange
     *
     * @return void
     */
    public function isTestnet()
    {
        return isset($this->exchangeEntity->isTestnet) ? 
            $this->exchangeEntity->isTestnet : false;
    }
    /**
     * Get exchange internal id
     *
     * @return string
     */
    public function getInternalId(): string
    {
        return $this->exchangeEntity->internalId;
    }
    /**
     * Get exchange type from array (e.g. signal array)
     *
     * @param array  $array    array
     * @param string $property property name to get value from
     * 
     * @return string
     */
    public static function getExchangeTypeFromArray($array, $property): string
    {
        return self::_getExchangeType(
            empty($array[$property]) ? 
                'SPOT' : $array[$property]
        );
    }
    /**
     * Normalize exchange type
     *
     * @param string $exchangeType exchange type
     * 
     * @return string
     */
    private static function _getExchangeType(string $exchangeType): string
    {
        $supportedExchangesAccountTypes = ['spot', 'futures', 'margin'];
        $normalizedExchangeType = strtolower($exchangeType);

        if (in_array($normalizedExchangeType, $supportedExchangesAccountTypes)) {
            return $normalizedExchangeType;
        }

        return 'spot';
    }
    /**
     * Get exchange name. 
     *
     * @return string
     */
    public function getName()
    {
        if (isset($this->psExchangeData->name)) {
            return $this->psExchangeData->name;
        }
        
        return $this->exchangeEntity->name;
    }

    /**
     * Get quote asset for this symbol
     *
     * @param string $zigSymbol symbol
     * @param string $quote     quote for symbol if provided
     * 
     * @return string
     */
    public function getQuote4PositionSize($zigSymbol, $quote = null)
    {
        if (null != $quote) {
            return $quote;
        }
        $marketEncoder = BaseMarketEncoder::newInstance(
            $this->getName(),
            $this->getExchangeType()
        );
        return $marketEncoder->getMarketQuote4PositionSize($zigSymbol);
    }
    /**
     * Get quote asset for this symbol ONLY for exchange position size settings
     *
     * @param string $zigSymbol symbol
     * @param string $quote     quote for symbol if provided
     * 
     * @return string
     */
    public function getQuote4PositionSizeExchangeSettings($zigSymbol, $quote = null)
    {
        return $this->getQuote4PositionSize($zigSymbol, $quote);
    }
    /**
     * Get position handler for this exchange
     *
     * @return PositionHandler
     */
    public function getPositionHandler()
    {
        return new PositionHandler(
            $this->getName(),
            $this->getExchangeType()
        );
    }
    /**
     * Get market encoder
     *
     * @return MarketEncoder
     */
    public function getMarketEncoder()
    {
        return $this->marketEncoder;
    }
    /**
     * Get exchange handler for this exchange type
     *
     * @return ExchangeHandler
     */
    public function getExchangeHandler()
    {
        return new ExchangeHandler(
            $this->getName(),
            $this->getExchangeType()
        );
    }
}