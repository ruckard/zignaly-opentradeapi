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


namespace Zignaly\Mediator;

use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\ExchangeHandler\ExchangeHandler;

/**
 * Position Cache mediator simplify the usage of position related services.
 *
 * @package Zignaly\Mediator
 */
class PositionCacheMediator
{
    /**
     * Position array from cache
     *
     * @var array
     */
    protected $position;
    protected $marketEncoder;
    /**
     * Constructor
     *
     * @param array $position
     */
    public function __construct(array $position)
    {
        $this->position = $position;
        $this->marketEncoder = BaseMarketEncoder::newInstance(
            $this->getExchangeName(),
            $this->getExchangeType()
        );
    }
    /**
     * Create from array
     *
     * @param array $position
     * 
     * @return PositionCacheMediator
     */
    public static function fromArray($position): PositionCacheMediator
    {
        if (ZignalyExchangeCodes::isBitmex($position['exchange'])) {
            return new BitmexPositionCacheMediator($position);
        } else {
            return new PositionCacheMediator($position);
        }
        
    }
    /**
     * Get exchange name
     *
     * @return string
     */
    public function getExchangeName()
    {
        return $this->position['exchange'];
    }
    /**
     * Get exchange type
     *
     * @return string
     */
    public function getExchangeType()
    {
        return $this->position['exchangeType'];
    }
    /**
     * Get internal exchange id
     *
     * @return string
     */
    public function getInternalExchangeId()
    {
        return $this->position['internalExchangeId'];
    }
    /**
     * Get zignaly symbol (pair)
     *
     * @return string
     */
    public function getSymbol()
    {
        return $this->position['pair'];
    }
    /**
     * Get base
     *
     * @return string
     */
    public function getBase()
    {
        return $this->position['base'];
    }
    /**
     * Get quote
     *
     * @return string
     */
    public function getQuote()
    {
        return $this->position['quote'];
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
            $this->getExchangeName(),
            $this->getExchangeType()
        );
    }   
}