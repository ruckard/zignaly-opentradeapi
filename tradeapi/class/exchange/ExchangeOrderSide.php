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

use Zignaly\exchange\exceptions;

class ExchangeOrderSide {
    const Buy     = 'buy';
    const Sell    = 'sell';
    /**
     * translate to ccxt string
     *
     * @param string $orderSide
     * @return string
     */
    public static function toCcxt (string $orderSide) {
        switch(\strtolower($orderSide)) {
            case self::Buy:
                return 'buy';
            case self::Sell:
                return 'sell';
            default:
                throw new exceptions\ExchangeInvalidFormatException ("Not valid order side ".$orderSide);
        }
    }
    /**
     * to side
     *
     * @param string $side
     * @return string
     */
    public static function fromCcxt (string $side) {
        switch ($side) {
            case 'buy':
                return self::Buy;
            case 'sell':
                return self::Sell;
            default:
                throw new exceptions\ExchangeInvalidFormatException ("Not valid order side from ccxt".$side);
        }
    }
}