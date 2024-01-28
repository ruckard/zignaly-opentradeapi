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


use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\Process\DIContainer;

class Order
{
    private $mongoDBLink;
    private $collectionName = 'order';
    /** @var Monolog $Monolog */
    private $Monolog;

    private $exchangeName;
    private $exchangeAccountType;


    public function __construct()
    {
        global $mongoDBLink;
        $this->mongoDBLink = $mongoDBLink;
        $container = DIContainer::getContainer();
        if (!$container->has('monolog')) {
            $container->set('monolog', new Monolog('OrderModel'));
        }
        $this->Monolog = $container->get('monolog');
    }

    /**
     * Insert one entry in the Order collection.
     *
     * @param array $orderDocument
     * @return bool
     */
    public function insert(array $orderDocument)
    {
        try {
            $orderDocument['i'] = "{$orderDocument['i']}";
            $orderDocument['t'] = "{$orderDocument['t']}";
            $this->mongoDBLink->selectCollection($this->collectionName)->insertOne($orderDocument);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), false === 'duplicate key error collection')) {
                $this->Monolog->sendEntry('critical', "Insert failed: " . $e->getMessage(), $orderDocument);
                return false;
            }
        }

        return true;
    }

    /**
     * Find the order in the db, parsing it for the instance or return false if it doesn't exists.
     * @param string $exchangeName
     * @param string $exchangeAccountType
     * @param string $orderId
     * @param string $symbol
     * @return array|bool
     */
    public function getOrder(string $exchangeName, string $exchangeAccountType, string $orderId, string $symbol)
    {
        if ('zignaly' === strtolower($exchangeName)) { //Todo: this is temporal, as soon as we add a second broker exchange, this won't work.
            $exchangeName = 'binance';
        } elseif ('binancefutures' === strtolower($exchangeName)) {
            $exchangeAccountType = 'futures';
            $exchangeName = 'binance';
        }

        $this->exchangeAccountType = $exchangeAccountType;
        $this->exchangeName = $exchangeName;

        $find = [
            'i' => $orderId,
            's' => $symbol,
            'exchangeName' => strtolower($this->exchangeName),
            'exchangeAccountType' => strtolower($this->exchangeAccountType),
        ];

        $options = [
            'sort' => [
                'T' => 1
            ],
        ];

        $retries = 0;

        do {
            sleep($retries);
            $retries++;
            $orders = $this->mongoDBLink->selectCollection($this->collectionName)->find($find, $options)->toArray();
            $statuses = [];
            foreach ($orders as $order) {
                $statuses[] = $order['X'];
            }

            if (empty($statuses)) {
                return false;
            }

            if (in_array('FILLED', $statuses)) {
                $status = 'FILLED';
                $internalStatus = 'closed';
            } elseif (in_array('CANCELED', $statuses)) {
                $status = 'CANCELED';
                $internalStatus = 'canceled';
            } elseif (in_array('EXPIRED', $statuses)) {
                $status = 'EXPIRED';
                $internalStatus = 'expired';
            } elseif (in_array('NEW_INSURANCE', $statuses)) {
                $status = 'NEW_INSURANCE';
                $internalStatus = 'closed';
            } elseif (in_array('NEW_ADL', $statuses)) {
                $status = 'NEW_ADL';
                $internalStatus = 'closed';
            } elseif (in_array('PARTIALLY_FILLED', $statuses)) {
                $status = 'PARTIALLY_FILLED';
                $internalStatus = 'open';
            } elseif (in_array('NEW', $statuses)) {
                $status = 'NEW';
                $internalStatus = 'open';
            } else {
                $this->Monolog->sendEntry('critical', "Unknown order status", $orders);
                return false;
            }

            $finalOrder = $this->composeMainOrder($orders, $status, $internalStatus);
            $retrieveOrders = $finalOrder ? $this->retryOrder($finalOrder, $retries) : false;

        } while ($retrieveOrders);

        return $finalOrder;
    }

    /**
     * Check if the amount from the trades and the filled amount are the same.
     * @param array $order
     * @param int $retries
     * @return bool
     */
    private function retryOrder(array $order, int $retries)
    {
        if (3 === $retries) {
            return false;
        }

        //If the difference is bigger than the epsilon, then we keep trying.
        $amountDifference = round($this->computeAmountFromTrades($order) - (float)$order['filled'], 8);

        return abs($amountDifference) >= PHP_FLOAT_EPSILON;
    }

    /**
     * Compute trades amount and return the total.
     * @param array $order
     * @return float
     */
    private function computeAmountFromTrades(array $order)
    {
        if (empty($order['trades'])) {
            return 0.0;
        }

        $amount = 0;

        foreach ($order['trades'] as $trade) {
            $amount += $trade['amount'];
        }

        return (float) $amount;
    }

    /**
     * Compose the formatted order array.
     * @param object $order
     * @param string $status
     * @param array $orders
     * @return array
     */
    private function composeOrder(object $order, string $status, array $orders)
    {
        $encoder = BaseMarketEncoder::newInstance($this->exchangeName, $this->exchangeAccountType);
        $symbol = $encoder->getZignalySymbolFromZignalyId($order['s']);
        if (null === $symbol) {
            $symbol = $order['s'];
        }

        $trades = $this->composeTrades($orders);
        $avgPrice = $this->getOrderPrice($trades);

        return [
            'id' => $order['i'],
            'datetime' => date('c', $order['T'] / 1000),
            'timestamp' => $order['T'],
            'lastTradeTimestamp' => $order['T'],
            'status' => $status,
            'symbol' => $symbol,
            'type'  => strtolower($order['o']),
            'side' => strtolower($order['S']),
            'price' => $avgPrice,
            'amount' => $order['q'],
            'filled' => $order['z'],
            'remaining' => $order['q'] - $order['z'],
            'cost' => $avgPrice * $order['z'],
            'trades' => $trades,
            'fee' => [
                'currency' => isset($order['N']) ? $order['N'] : false,
                'cost' => $this->getOrderTotalFees($trades),
            ],
            'reduceOnly' => !empty($orders['R']),
            'zignalyClientId' => $order['c'],
            'info' => $order,
            'orderFromStream' => true,
        ];
    }

    /**
     * Get order average price.
     * @param array $trades
     * @return float
     */
    private function getOrderPrice(array $trades)
    {
        if (empty($trades)) {
            return 0.0;
        }

        $totalAmount = 0.0;
        $totalCost = 0.0;

        foreach ($trades as $trade) {
            $amount = $trade['amount'];
            $price = $trade['price'];
            $cost = $amount * $price;
            $totalCost += $cost;
            $totalAmount += $amount;
        }
        $avgPrice = $totalAmount > 0 ? $totalCost / $totalAmount : 0.0;

        return (float)$avgPrice;
    }

    /**
     * Compute the total fees from the trades.
     * @param array $trades
     * @return float|mixed
     */
    private function getOrderTotalFees(array $trades)
    {
        if (empty($trades)) {
            return 0.0;
        }

        $fees = 0.0;
        foreach ($trades as $trade) {
            $fees += $trade['fee']['cost'];
        }

        return $fees;
    }

    /**
     * Compose the list of trades from a filled or partially filled order.
     * @param array $orders
     * @return array
     */
    private function composeTrades(array $orders)
    {
        $trades = [];
        foreach ($orders as $order) {
            if (empty($order['t']) || "-1" === $order['t']) {
                continue;
            }
            $trades[] = [
                'id' => $order['t'],
                'datetime' => date('c', $order['T'] / 1000),
                'timestamp' => $order['T'],
                'symbol' => $order['s'],
                'order' => $order['i'],
                'type'  => strtolower($order['o']),
                'side' => strtolower($order['S']),
                'takerOrMaker' => $order['m'] ? 'maker' : 'taker',
                'price' => $order['L'],
                'amount' => $order['l'],
                'cost' => $order['l'] * $order['L'],
                'fee' => [
                    'currency' => isset($order['N']) ? $order['N'] : false,
                    'cost' => isset($order['n']) ? $order['n'] : false,
                ],
                'info' => $order,
                'tradeFromStream' => true,
            ];
        }

        return $trades;
    }

    /**
     * Look for the valid order and return it.
     * @param array $orders
     * @param string $status
     * @param string $internalStatus
     * @return array|bool
     */
    private function composeMainOrder(array $orders, string $status, string $internalStatus)
    {
        foreach ($orders as $o) {
            if ($status === $o['X']) {
                $order = $o;
            }
        }

        return empty($order) ? false : $this->composeOrder($order, $internalStatus, $orders);
    }
}
