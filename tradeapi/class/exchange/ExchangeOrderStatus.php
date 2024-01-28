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

class ExchangeOrderStatus {
    const Open     = 'open';
    const Closed   = 'closed';
    const Canceled = 'cancelled';
    const Expired = 'expired';
    const Rejected = 'rejected';

    /**
     * translate to ccxt strings
     *
     * @param string $orderSide
     * @return string
     */
    public static function toCcxt (string $status) {
        switch(\strtolower($status)) {
            case self::Open:
                return 'open';
            case self::Closed:
                return 'closed';
            case self::Canceled:
                return 'canceled';
            case self::Expired:
                return 'expired';
            case self::Rejected:
                return 'rejected';
            default:
                throw new exceptions\ExchangeInvalidFormatException ("Not valid order status ".$status);
        }
    }
    /**
     * to status
     *
     * @param string $orderSide
     * @return string
     */
    public static function fromCcxt (string $status) {
        switch ($status) {
            case 'open':
                return self::Open;
            case 'closed':
                return self::Closed;
            case 'canceled':
                return self::Canceled;
            case 'expired': 
                return self::Expired;
            case 'rejected':
                return self::Rejected;
            default:
                throw new exceptions\ExchangeInvalidFormatException ("Not valid order status from ccxt ".$status);
        }
    }
}
