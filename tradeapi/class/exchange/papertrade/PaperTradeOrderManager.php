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

namespace  Zignaly\exchange\papertrade;

use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeOrder;

interface PaperTradeOrderManager {
    /**
     * virtual create order
     *
     * @param string $exchangeId
     * @param string $symbol
     * @param string $orderType
     * @param string $orderSide
     * @param float $amount
     * @param float $price
     * @param ExchangeExtraParams $params
     * @param string $positionId
     * @return ExchangeOrder
     */
    public function createOrder (string $exchangeId, string $symbol, string $orderType,
        string $orderSide, float $amount, float $price = null, ExchangeExtraParams $params = null,
        $positionId = null);
    /**
     * virtual get order status
     *
     * @param string $exchangeId
     * @param string $orderId
     * @param string $symbol
     * @return ExchangeOrder
     */
    public function getOrderStatus (string $exchangeId, string $orderId, string $symbol);
    /**
     * virtual get orders
     *
     * @param string $exchangeId
     * @param string $symbol
     * @return ExchangeOrder[]
     */
    public function getOrders(string $exchangeId, string $symbol);
    /**
     * virtual cancel order
     *
     * @param string $exchangeId
     * @param string $orderId
     * @param string $symbol
     * @return ExchangeOrder
     */
    public function cancelOrder(string $exchangeId, string $orderId, string $symbol);
    /**
     * virtual fetch balance
     *
     * @return void
     */
    public function fetchBalance();
}