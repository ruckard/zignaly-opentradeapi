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

class ExchangeTradeMakerOrTaker {
    const Unknown  = -1;
    const Maker   = 0;
    const Taker    = 1;
    /**
     * translate to ccxt strings
     *
     * @param ExchangeOrderType $orderType
     * @return string
     */
    public static function toCcxt (ExchangeOrderType $orderType) {
        switch($orderType) {
            case self::Maker:
                return 'maker';
            case self::Taker:
                return 'taker';
            default:
                return 'maker'; // TODO: returns maker or exception????
        }
    }
    /**
     * to type
     *
     * @param string|null $type
     * @return ExchangeTradeMakerOrTaker
     */
    public static function fromCcxt ($type) {
        switch ($type) {
            case 'maker':
                return self::Maker;
            case 'taker':
                return self::Taker;
            default:
                self::Unknown;
        }
    }
}