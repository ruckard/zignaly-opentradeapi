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


namespace Zignaly\exchange\ccxtwrap;

use Zignaly\exchange\ExchangeWithdrawal;

/**
 * Exchange ccxt withdrawal
 *
 * {
 *   'info': response,
 *   'id': this.safeString (response, 'id'),
 * };
 */
class ExchangeWithdrawalCcxt implements ExchangeWithdrawal
{
    protected $ccxtResponse;

    public function __construct($ccxtResponse)
    {
        $this->ccxtResponse = $ccxtResponse;
    }

    public function getCcxtResponse()
    {
        return $this->ccxtResponse;
    }

    private function get($key)
    {
        if (isset($this->ccxtResponse[$key])) {
            return $this->ccxtResponse[$key];
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getId()
    {
        return $this->get("id");
    }

    /**
     * @inheritDoc
     */
    public function getMessage()
    {
        $info = $this->get("info");

        if ($info && isset($info['msg'])) {
            return $info['msg'];
        }

        return null;
    }
}