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

abstract class ExchangeOrderType {
    const Market   = 'market';
    const Limit    = 'limit';
    const Stop     = 'stop';
    const StopLimit = 'stop-limit';
    const StopLossLimit = "stop-loss-limit";

    const CcxtMarket    = 'market';
    const CcxtLimit     = 'limit';
    const CcxtStop      = 'stop';
    const CcxtStopLimit = "stopLimit";
    const CcxtStopLossLimit = "stopLossLimit";
    const CcxtCeilingMarket = "ceilingMarket";
    /**
     * translate to ccxt strings
     *
     * @param string $orderType
     * @return string
     */
    public static function toCcxt (string $orderType) {
        switch(\strtolower($orderType)) {
            case self::Market:
                return self::CcxtMarket;
            case self::Limit:
                return self::CcxtLimit;
            case self::Stop:
                return self::CcxtStop;
            case self::StopLimit:
                return self::CcxtStopLimit;
            case self::StopLossLimit:
                return self::CcxtStopLossLimit;
            default:
                throw new exceptions\ExchangeInvalidFormatException ("Not valid order type ".$orderType);
        }
    }
    /**
     * to type
     *
     * @param string $type
     * @return string
     */
    public static function fromCcxt (string $type) {
        switch ($type) {
            case self::CcxtMarket:
                return self::Market;
            case self::CcxtCeilingMarket:
                return self::Market;
            case self::CcxtLimit:
                return self::Limit;
            case self::CcxtStop:
                return self::Stop;
            case self::CcxtStopLimit:
                return self::StopLimit;
            case self::CcxtStopLossLimit:
                return self::StopLossLimit;
            default:
                throw new exceptions\ExchangeInvalidFormatException ("Not valid order type from ccxt ".$type);
        }
    }
}