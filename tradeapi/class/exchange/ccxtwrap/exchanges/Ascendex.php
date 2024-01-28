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



namespace Zignaly\exchange\ccxtwrap\exchanges;


use Zignaly\exchange\ccxtwrap\BaseExchangeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\ExchangeOrder;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ZignalyExchangeCodes;

class Ascendex extends BaseExchangeCcxt
{
    public function __construct(ExchangeOptions $options)
    {
        parent::__construct("ascendex", $options);
    }

    public function getId()
    {
        return ZignalyExchangeCodes::ZignalyAscendex;
    }

    protected function parseCcxtException($ccxtException, $message = "")
    {
        // to be filled to
        return parent::parseCcxtException($ccxtException, $message);
    }

    public function orderInfo(string $orderId, string $symbol = null)
    {
        try {
            $order = $this->exchange->fetchOrder($orderId, $symbol);

            $trades = [];

            if ('open' !== $order['status'] && $order['filled'] > 0) {
                $trades[] = array(
                    'info' => $order['info'],
                    'timestamp' => $order['lastTradeTimestamp'],
                    'datetime' => $this->exchange->iso8601($order['lastTradeTimestamp']),
                    'symbol' => $order['symbol'],
                    'id' => $order['id'],
                    'order' => $order['id'],
                    'type' => null,
                    'takerOrMaker' => $order['side'] === 'buy'
                        ? 'maker' : 'taker',
                    'side' => $order['side'],
                    'price' => $order['average'],
                    'amount' => $order['filled'],
                    'cost' => $order['cost'],
                    'fee' => $order['fee'],
                );
            }

            $order['trades'] = $trades;

            return new ExchangeOrderCcxt($order);
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * set auth info (changeUser)
     *
     * @param string $apiKey
     * @param string $apiSecret
     * @param string $password
     *
     * @return void
     */
    public function setAuth(string $apiKey, string $apiSecret, string $password = "")
    {
        parent::setAuth($apiKey, $apiSecret, $password = "");
        $this->exchange->options['account-group'] = null;
    }

    /**
     * create order
     *
     * @param string $symbol
     * @param string $orderType
     * @param atring $order
     * @param float $amount
     * @param float $price
     * @param string $positionId
     *
     * @return ExchangeOrder
     */
    public function createOrder(
        string $symbol,
        string $orderType,
        string $orderSide,
        float $amount,
        float $price = null,
        ExchangeExtraParams $params = null,
        $positionId = false
    ) {
        $createdOrder = parent::createOrder($symbol, $orderType, $orderSide, $amount, $price, $params, $positionId);
        $orderInfo = $this->orderInfo($createdOrder->getId(), $symbol);
        $createdResponse = $createdOrder->getCcxtResponse();
        $originalResponse = $orderInfo->getCcxtResponse();
        $originalResponse['timestamp'] = $createdResponse['timestamp'];
        $originalResponse['datetime'] = $createdResponse['datetime'];
        return new ExchangeOrderCcxt($originalResponse);
    }
}
