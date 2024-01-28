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

namespace Zignaly\exchange\ccxtwrap\ccxtpatch;

use ccxt;
use Zignaly\exchange\zignalyratelimiter\RateLimitTrait;

class binance extends ccxt\binance {
    use RateLimitTrait;

    public function parse_balance($balance, $type = null, $marginMode = null)
    {
        $parsed = parent::parse_balance($balance, $type, $marginMode);

        if (isset($balance['assets']) && 'future' === $type) {
            $assets = $balance['assets'];
            foreach($assets as $asset) {
                $code = $asset['asset'] ?? null;
                if ($code && isset($parsed[$code])) {
                    $parsed[$code]['current_margin'] = $parsed[$code]['used'];
                    $parsed[$code]['wallet'] = $this->safe_float($asset, 'walletBalance');
                    $parsed[$code]['margin'] = $this->safe_float($asset, 'marginBalance');
                    $parsed[$code]['unrealized_profit'] = $this->safe_float($asset, 'unrealizedProfit');
                }
            }
        }

        return $parsed;
    }

    /**
     * @param int $from
     * @param string $asset
     * @return mixed
     */
    public function getFuturesTransfers(int $from, string $asset)
    {
        $query = ['startTime' => $from, 'asset' => $asset];

        return $this->sapiGetFuturesTransfer($query);
    }

    /**
     * @param $code
     * @return array|mixed
     */
    public function currency($code)
    {
        $result = null;
        try {
            $result = parent::currency($code);
        } catch (ccxt\ExchangeError $exception) {
        }

        return \is_array($result)? $result : $this->safe_currency($code);
    }
}
