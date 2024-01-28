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
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\ExchangePosition;
use Zignaly\exchange\exceptions;
use Zignaly\exchange\ccxtwrap\ExchangePositionCcxt;
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeTradeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeTradeFeeCcxt;
use Zignaly\exchange\exceptions\ExchangeInvalidFormatException;
use Zignaly\exchange\ExchangeOrder;
use Zignaly\exchange\ExchangeTrade;
use Zignaly\exchange\ExchangeTradeFee;
use Zignaly\exchange\ZignalyExchangeCodes;

class Kucoin extends BaseExchangeCcxt {
    public function __construct (ExchangeOptions $options) {
        parent::__construct ("kucoin", $options);
    }
    /**
     * get exchange if (zignaly internal code)
     *
     * @return void
     */
    public function getId(){
        return ZignalyExchangeCodes::ZignalyKucoin;
    }

    /**
     * create order
     * stop-limit orders set stop param to 'entry' if buy or 'loss' when sell!!!
     *   stop: 'loss': Triggers when the last trade price changes to a value at or below the stopPrice.
     *   stop: 'entry': Triggers when the last trade price changes to a value at or above the stopPrice.
     *
     * @param string $symbol
     * @param string $orderType
     * @param atring $order
     * @param float $amount
     * @param float $price
     * @param ExchangeExtraParams $params
     * @param string $positionId
     * @return ExchangeOrder
     */
    public function createOrder (string $symbol, string $orderType,
        string $orderSide, float $amount, float $price = null, ExchangeExtraParams $params = null,
        $positionId = false) {

            try {
                $type = ExchangeOrderType::toCcxt ($orderType);
                if (($type == ExchangeOrderType::CcxtStopLimit)){
                    if (($params != null) && ($params->getStopPrice() == null)){
                        throw new ExchangeInvalidFormatException("stop price not set in stop-limit order creation");
                    }
                }
                if ($type == ExchangeOrderType::CcxtStopLimit){
                    $type = ExchangeOrderType::CcxtLimit;
                }
                $side = ExchangeOrderSide::toCcxt ($orderSide);
                $ps = array();
                if (($params != null) && ($params->getStopPrice() != null)) {
                    $ps["stopPrice"] = $params->getStopPrice();
                    $ps["stop"] = ($side == ExchangeOrderSide::Buy)?'entry':'loss';
                }

                $zignalyPositionId = null;
                if (null != $params) {
                    $zignalyPositionId = $params->getZignalyPositionId();
                    if (null != $zignalyPositionId) {
                        $ps['clientOid'] = 'Zig'
                            .$zignalyPositionId.""
                            .floor(microtime(true) * 1000);
                    }
                }

                $order = $this->exchange->create_order ($symbol, $type, $side, $amount, $price, $ps);
                // set original order type
                $order["type"] = $orderType;
                if (null != $zignalyPositionId) {
                    $order['zignalyClientId'] = $ps['clientOid'];
                }

                $order['recvClientId'] = $this->exchange->safe_string($order['info'], 'clientOid');

                return new ExchangeOrderCcxt($order);
            } catch (\ccxt\BaseError $ex) {
                throw $this->parseCcxtException ($ex, $ex->getMessage());
            }
    }

    /**
     * order info
     *
     * @param ExchangeOrder $order
     * @return ExchangeOrder
     */
    public function orderInfo (string $orderId, string $symbol = null) {
        try {
            $ccxtOrder = $this->exchange->fetchOrder ($orderId, $symbol);
            $this->fixCcxtOrderInfo($ccxtOrder);
            $exchangeOrder = new ExchangeOrderCcxt ($ccxtOrder);
            if (($exchangeOrder->getStatus() == ExchangeOrderStatus::Closed)
                || ($exchangeOrder->getStatus() == ExchangeOrderStatus::Canceled && $exchangeOrder->getFilled() > 0)
                || ($exchangeOrder->getStatus() == ExchangeOrderStatus::Expired && $exchangeOrder->getFilled() > 0)
            ) {
                $trades = array();
                $request = array(
                    'orderId' => $orderId
                );
                $currentOrderFill = 0;
                $targetOrderFill = $exchangeOrder->getFilled();
                $maxNumRequests = 4;
                while ($maxNumRequests > 0){
                    $myTradesResponse = $this->exchange->fetchMyTrades($symbol, null, 500, $request);
                    if (count($myTradesResponse) === 0) break;
                    foreach($myTradesResponse as $tradeElement) {
                        $currentTrade = new ExchangeTradeCcxt($tradeElement);
                        if ($currentTrade->getOrderId() === $orderId) {
                            $trades[] = $currentTrade;
                            $currentOrderFill += $currentTrade->getAmount();
                            if ($currentOrderFill >= $targetOrderFill) {
                              $maxNumRequests = 0;
                              break;
                            }
                        }
                        $request['fromId'] = $currentTrade->getId();
                    }
                    $maxNumRequests--;
                }
                // TODO: LFERN remove this when kucoin fix the problem
                if (count($trades) == 0 && $exchangeOrder->getId() != '') {
                    $trades[] = new ExchangeTradeCcxt([
                        'info' => [],
                        'id' => $exchangeOrder->getId() . '-fake',
                        'order' => $exchangeOrder->getId(),
                        'timestamp' => $exchangeOrder->getTimestamp(),
                        'datetime' => $this->exchange->iso8601($exchangeOrder->getTimestamp()),
                        'symbol' => $exchangeOrder->getSymbol(),
                        'type' => $exchangeOrder->getType(),
                        'takerOrMaker'=> false,
                        'side' => $exchangeOrder->getSide(),
                        'price' => $exchangeOrder->getPrice(),
                        'amount' => $exchangeOrder->getFilled(),
                        'cost' => $exchangeOrder->getCost(),
                        'fee' => [
                            'cost' => $exchangeOrder->getFeeCost(),
                            'currency' => $exchangeOrder->getFeeCurrency(),
                            'rate' => false,
                        ]
                    ]);
                }

                $exchangeOrder->setTrades ($trades);
            }
            return $exchangeOrder;
        } catch (\ccxt\BaseError $ex) {
            throw $this->parseCcxtException ($ex, $ex->getMessage());
        }
    }
    /**
     * Undocumented function
     *
     * @return ExchangeOrder[]
     */
    public function getClosedOrders ($symbol = null, $since = null, $limit = null){
        try {
            $ret = array();
            $orders = $this->exchange->fetchClosedOrders($symbol, $since, $limit);
            foreach($orders as $order) {
                $this->fixCcxtOrderInfo($order);
                $ret[] = new ExchangeOrderCcxt($order);
            }
            return $ret;
        } catch (\ccxt\BaseError $ex) {
            throw $this->parseCcxtException ($ex, $ex->getMessage());
        }
    }
    private function fixCcxtOrderInfo(&$ccxtOrder){
        if (isset($ccxtOrder['info']) && 
            ($ccxtOrder["type"] == ExchangeOrderType::CcxtLimit) && 
            (
                (isset($ccxtOrder['info']['stop']) && ($ccxtOrder['info']['stop'] != "")) || 
                (isset($ccxtOrder['info']['stopPrice']) && ($ccxtOrder['info']['stopPrice'] > 0))
            )
        ){
            // was a stop limit
            $ccxtOrder["type"] = ExchangeOrderType::CcxtStopLimit;
        } 
    }
    /**
     * parse ccxt exception
     *
     * @param \ccxt\BaseError $ccxtException
     * @param string $message
     * @return ExchangeException
     */
    protected function parseCcxtException ($ccxtException, $message = ""){
        $mesg = $ccxtException->getMessage();
        if (strpos($mesg,"{\"code\":\"400200\",\"msg\":\"Unknown partner\"}")){
            // {"code":"400200","msg":"Unknown partner"} 
            return new exceptions\ExchangeAuthException ($mesg, 0, $ccxtException); 
        } else if (strpos($mesg,"{\"code\":\"400201\",\"msg\":\"Invalid KC-API-PARTNER-SIGN\"}")) {
            // {"code":"400201","msg":"Invalid KC-API-PARTNER-SIGN"}
            return new exceptions\ExchangeAuthException ($mesg, 0, $ccxtException); 
        }
        
        return parent::parseCcxtException($ccxtException, $message);
    }

    /**
     * Calculate fee for order
     *
     * @param ExchangeTrade $order
     * @return ExchangeTradeFee
     */
    public function calculateFeeForTrade(ExchangeTrade $trade){
        $symbol = $trade->getSymbol();
        $market = $this->exchange->market($symbol);
        $quote = $market['quote'];
        $feeCost = $trade->getCost() * 0.001;// 0.0005;

        return new ExchangeTradeFeeCcxt($feeCost, $quote, null);
    }

    /**
     * @inheritDoc
     */
    public function withdrawCurrencyNetworkPrecision(string $currencyCode, string $network, float $amount)
    {
        return parent::withdrawCurrencyNetworkPrecision($currencyCode, $network, $amount);
    }

    /**
     * cancel order
     *
     * @param string $orderId
     * @param string $symbol
     *
     * @return ExchangeOrder
     */
    public function cancelOrder(string $orderId, string $symbol = null)
    {
        try {
            $this->exchange->cancel_order($orderId, $symbol);
            return $this->orderInfo($orderId, $symbol);
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }
}
