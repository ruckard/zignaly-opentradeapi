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

use MongoDB\Model\BSONDocument;
use ccxt;
use Zignaly\exchange\ccxtwrap\BaseExchangeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeTradeCcxt;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ZignalyExchangeCodes;

class Vcce extends BaseExchangeCcxt
{
    /**
     * Constructor
     *
     * @param ExchangeOptions $options exchange options
     */
    public function __construct(ExchangeOptions $options)
    {
        parent::__construct("vcc", $options);
    }

    /**
     * Get exchange if (zignaly internal code)
     *
     * @return string
     */
    public function getId()
    {
        return ZignalyExchangeCodes::ZignalyVcce;
    }
    /**
     * Parse ccxt exception
     *
     * @param \ccxt\BaseError $ccxtException ccxt exception
     * @param string $message custom message
     * 
     * @return void
     */
    protected function parseCcxtException($ccxtException, $message = "")
    {
        // to be filled to 
        return parent::parseCcxtException($ccxtException, $message);
    }

    /**
     * Undocumented function
     *
     * @param ExchangeOrder $order
     *
     * @return ExchangeOrder
     */
    public function orderInfo(string $orderId, string $symbol = null)
    {
        try {
            $exchangeOrder = new ExchangeOrderCcxt($this->exchange->fetchOrder($orderId, $symbol));
            // get all trades for this symbols from timestamp to now (we would need order id from vcce trades)
            $trades = array();
            if (($exchangeOrder->getStatus() == ExchangeOrderStatus::Closed)
                || ($exchangeOrder->getStatus() == ExchangeOrderStatus::Canceled && $exchangeOrder->getFilled() > 0)
                || ($exchangeOrder->getStatus() == ExchangeOrderStatus::Expired && $exchangeOrder->getFilled() > 0)
            ) {
/*                
                $trades = $this->exchange->fetch_my_trades($symbol, $exchangeOrder->getTimestamp());//, null, null, array ('filter'=> array('orderID' => $orderId)));
                $tradeArray = array();
                foreach ($trades as $trade) {
                    // current ccxt does not parse id
                    $trade['id'] = $this->exchange->safe_string($trade['info'], 'id');
                    // HACK TO ASSING A ORDER ID FOR NOW
                    $trade['order'] = $orderId;
                    $tradeArray[] = new ExchangeTradeCcxt($trade);
                }
*/                
                
                $request = array(
                    'order_id' => $exchangeOrder->getId(),
                );
                $response = $this->exchange->privateGetOrdersOrderIdTrades($request);
                $trades = $this->exchange->safe_value($response, 'data', array());
                $tradeArray = array();
                foreach ($trades as $trade) {
                    $parseTrade = $this->exchange->parse_trade($trade);
                    $parseTrade['id'] = $this->exchange->safe_string($parseTrade['info'], 'id');
                    $parseTrade['side'] = $this->exchange->safe_string($trade, 'side');
                    // HACK TO ASSING A ORDER ID FOR NOW
                    $parseTrade['order'] = $orderId;
                    $tradeArray[] = new ExchangeTradeCcxt($parseTrade);
                }

                
                $exchangeOrder->setTrades($tradeArray);
            }
            
            return $exchangeOrder;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
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
        try {
            $type = ExchangeOrderType::toCcxt($orderType);
            $side = ExchangeOrderSide::toCcxt($orderSide);
            
            $sendingType = $type;
            $ps = array();
            if ((ExchangeOrderType::CcxtMarket == $sendingType) 
                && ($params != null) 
                && ($params->getQuoteOrderQty() != null)
            ) {
                $ps["ceiling"] = $params->getQuoteOrderQty();
                $sendingType = ExchangeOrderType::CcxtCeilingMarket;
            }

            $order = $this->exchange->create_order($symbol, $sendingType, $side, $amount, $price, $ps);

            return new ExchangeOrderCcxt($order);
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }
}
