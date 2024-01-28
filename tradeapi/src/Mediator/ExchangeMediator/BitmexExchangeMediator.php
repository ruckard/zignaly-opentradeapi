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

use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\ExchangeHandler\BitmexExchangeHandler;
use Zignaly\Mediator\PositionHandler\BitmexPositionHandler;

/**
 * Exchange mediator mongo db exchange record for Bitmex exchange
 */
class BitmexExchangeMediator extends ExchangeMediator
{
    /**
     * Constructor
     *
     * @param \MongoDB\Model\BSONDocument $exchange mongo exchange record
     */
    public function __construct($exchange)
    {
        parent::__construct($exchange);
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
        $marketEncoder = BaseMarketEncoder::newInstance(
            $this->getName(),
            $this->getExchangeType()
        );
        $asset = $marketEncoder->getMarketQuote4PositionSize($zigSymbol);

        return $asset === 'XBT' ? 'BTC' : $asset;
    }
    /**
     * Get position handler for this exchange
     *
     * @return PositionHandler
     */
    public function getPositionHandler()
    {
        return new BitmexPositionHandler();
    }
    /**
     * Get exchange handler for this exchange type
     *
     * @return ExchangeHandler
     */
    public function getExchangeHandler()
    {
        return new BitmexExchangeHandler();
    }
}