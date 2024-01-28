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

/**
 * Exchange mediator when position don't have exchange object
 * Old records
 */
class NoExchangeMediator extends ExchangeMediator
{
    /**
     * Constructor
     */
    public function __construct()
    {
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
        return "spot";
    }
    /**
     * Is testnet exchange
     *
     * @return void
     */
    public function isTestnet()
    {
        return false;
    }
    /**
     * Get exchange internal id
     *
     * @return string
     */
    public function getInternalId(): string
    {
        return false;
    }
    /**
     * Get exchange name. 
     *
     * @return string
     */
    public function getName()
    {
        return "Binance";
    }
}