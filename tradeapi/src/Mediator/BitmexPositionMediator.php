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

/**
 * Implement some specific methods for Bitmex exchange about position
 * and to make some decisions depending of exchange markets
 */
class BitmexPositionMediator extends PositionMediator
{
    /**
     * Constructor
     *
     * @param \MongoDB\Model\BSONDocument $position zignaly position
     */
    public function __construct($position)
    {
        parent::__construct($position);
    }

    /**
     * Get the position symbol.
     *
     * @return string
     */
    public function getSymbol()
    {
        return $this->positionEntity->signal->pair;
    }

    /**
     * Get the position symbol.
     *
     * @return string
     */
    public function getSymbolWithSlash()
    {
        return $this->getCcxtSymbol();
    }

    /**
     * Get CCXT symbol
     *
     * @return string
     */
    public function getCcxtSymbol()
    {
        return $this->exchangeMediator->getMarketEncoder()
            ->toCcxt($this->positionEntity->signal->pair);
    }
    /**
     * Get array with extra info to be added to returned positions to FE
     *
     * @param bool $includePair
     * 
     * @return array
     */
    public function getExtraSymbolsAsArray($includePair = false)
    {
        $marketData = $this->getExchangeMediator()
            ->getMarketEncoder()->getMarket($this->getSymbol());
        if (null === $marketData) {
            throw new \Exception("Market data not found for symbol {$this->getSymbol()}");
        }
        $ret = [
            'unitsInvestment' => $marketData->getUnitsInvestment(),
            'unitsAmount'     => $marketData->getUnitsAmount(),
            'short'           => $marketData->getShort(),
            'tradeViewSymbol' => $marketData->getTradeViewSymbol(),
        ];

        if ($includePair) {
            $ret['pair'] = $this->getSymbol();
        }

        return $ret;
    }
}