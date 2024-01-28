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
use stdClass;
use ccxt;
use Zignaly\exchange\ccxtwrap\BaseExchangeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeBalanceCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeIncomeCcxt;
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\ExchangePosition;
use Zignaly\exchange\exceptions;
use Zignaly\exchange\ccxtwrap\ExchangePositionCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeTradeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeTradeFeeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeWithdrawalCcxt;
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeIncomeType;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\ExchangeTrade;
use Zignaly\exchange\ExchangeTradeFee;
use Zignaly\exchange\ExchangeTradeMakerOrTaker;
use Zignaly\exchange\marketencoding\BitmexMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;

class Bitmex extends BaseExchangeCcxt {

    protected $incomeTypes = array (
        'Funding' => ExchangeIncomeType::FundinfFee
    );

    public function __construct (ExchangeOptions $options) {
        parent::__construct ("bitmex", $options);
    }

    /**
     * get exchange if (zignaly internal code)
     *
     * @return void
     */
    public function getId(){
        return ZignalyExchangeCodes::ZignalyBitmex;
    }
    /**
     * Undocumented function
     *
     * @param \ccxt\BaseError $ccxtException
     * @param string $message
     * @return void
     */
    protected function parseCcxtException ($ccxtException, $message = ""){
        if (strrpos ($ccxtException->getMessage() , "\"error\":{\"message\":\"Not Found\"") !== FALSE){
            return new exceptions\ExchangeOrderNotFoundException($message, 0, $ccxtException);
        }
        return parent::parseCcxtException ($ccxtException, $message);
    }

    /**
     * Get position for associated account
     *
     * @return ExchangePosition[]
     */
    public function getPosition () {
        try {
            $arr = array();
            $response = $this->exchange->privateGetPosition ();
            foreach ($response as $elem) {
                $crossMargin = $elem['crossMargin'] ?? false;
                $pos = array(
                    'symbol' => $this->getSymbol4Id($this->exchange->safe_string($elem, "symbol")),
                    'amount' => $this->exchange->safe_float($elem, 'currentQty'),
                    'entryprice' => $this->exchange->safe_float($elem, 'avgEntryPrice'),
                    'markprice' => $this->exchange->safe_float($elem, 'markPrice'),
                    'liquidationprice' => $this->exchange->safe_float($elem, 'liquidationPrice'),
                    'leverage' => $this->exchange->safe_float($elem, 'leverage'),
                    'margin' => $this->exchange->safe_float($elem, 'maintMargin', 0.0)/ (10 ** 8),
                    'side' => "both",
                    'isolated' => !$crossMargin,
                    'info' => $elem
                );
                $arr[] = new ExchangePositionCcxt($pos);
            }
            return $arr;
        } catch (\ccxt\BaseError $ex){
            throw $this->parseCcxtException ($ex, $ex->getMessage());
        }
    }

    /**
     * Undocumented function
     *
     * @param string $orderId
     * @return ExchangeOrder
     */
    public function orderInfo (string $orderId, string $symbol = null)
    {
        try {
            $exchangeOrder = new ExchangeOrderCcxt(
                $this->fixOrderStatus(
                    $this->exchange->fetchOrder($orderId, $symbol)
                )
            );
            $trades = array();
            if (($exchangeOrder->getStatus() == ExchangeOrderStatus::Closed) || 
                ($exchangeOrder->getStatus() == ExchangeOrderStatus::Canceled && $exchangeOrder->getFilled() > 0)) {
                $trades = $this->exchange->fetch_my_trades ($symbol, null, null, array ('filter'=> array('orderID' => $orderId)));
                $tradeArray = array();
                foreach ($trades as $trade){
                    // fix trade price https://github.com/ccxt/ccxt/commit/02b10b277edd584a726e7fa234fd5c0f8d5de55d
                    $avgPx = $this->exchange->safe_float($trade['info'], 'avgPx');
                    if (null !== $avgPx) {
                        $trade['price'] = $avgPx;
                    }
                    $tradeArray[] = new ExchangeTradeCcxt($trade);
                }
                $exchangeOrder->setTrades ($tradeArray);
            }
            return $exchangeOrder;
        } catch (\ccxt\BaseError $ex) {
            throw $this->parseCcxtException ($ex, $ex->getMessage());
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
     * @param ExchangeExtraParams $params
     * @param string $positionId
     * @return ExchangeOrder
     */
    public function createOrder (string $symbol, string $orderType, 
        string $orderSide, float $amount, float $price = null, ExchangeExtraParams $params = null,
        $positionId = false) {

        try {
            $type = ExchangeOrderType::toCcxt($orderType);
            $side = ExchangeOrderSide::toCcxt($orderSide);
            // bitmex does not accept a market order with price
            $ps = array(
                'text' => 'Sent from Zignaly'
            );
            if ($type == ExchangeOrderType::CcxtMarket) {
                $price = null;
            } else {
                // check if post only when no market order
                if (true === $params->getPostOnly()) {
                    $ps['execInst'] = 'ParticipateDoNotInitiate';
                }
            }
            if (null != $params) {
                $zignalyPositionId = $params->getZignalyPositionId();
                if (null != $zignalyPositionId) {
                    $ps['clOrdID'] = $zignalyPositionId
                        .floor(microtime(true) * 100);
                }

                if ($params->getStopPrice() != null) {
                    $ps["stopPx"] = $params->getStopPrice();
                }
            }
            
            $order = $this->exchange->create_order($symbol, $type, $side, $amount, $price, $ps);
            if (null != $zignalyPositionId) {
                $order['zignalyClientId'] = $ps['clOrdID'];
            }

            $order['recvClientId'] = $this->exchange->safe_string($order['info'], 'clOrdID');

            return new ExchangeOrderCcxt($this->fixOrderStatus($order));
        } catch (\ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function withdrawCurrencyNetworkPrecision(string $currencyCode, string $network, float $amount)
    {
        return parent::withdrawCurrencyNetworkPrecision($currencyCode, $network, $amount);
    }
    /**
     * @inheritDoc
     */
    public function getLeverage(string $symbol): \stdClass
    {
        $positionArray = $this->getPosition();
        foreach($positionArray as $position) {
            if ($position->getSymbol() == $symbol) {
                return (object)[
                    'leverage' => $position->getLeverage(),
                    'maxLeverage' => 100.0
                ];
            }
        }
        return new \stdClass();
    }

    /**
     * @inheritDoc
     */
    public function changeLeverage(string $symbol, int $leverage): \stdClass
    {
        $rawSymbol = $symbol;
        $params = [
            'symbol' => $rawSymbol,
            'leverage' => $leverage,
        ];

        $updateLeverage = $this->exchange->privatePostPositionLeverage($params);

        $data = [
            "$rawSymbol" => [
                'leverage' => $updateLeverage['leverage'],
            ],
        ];

        return (object)$data;
    }

    /**
     * @inheritDoc
     */
    public function withdraw($code, $amount, $address, $tag = null, $network = null)
    {
        try {
            $params = array();
            if ($network != null) {
                $params["network"] = $network;
            }

            $params["name"] = "";

            return new ExchangeWithdrawalCcxt($this->exchange->withdraw($code, $amount, $address, $tag, $params));
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function fetchBalance()
    {
        try {
            $balance = $this->exchange->fetch_balance();
            for ($i = 0; $i < count($balance['info']); $i++) {
                $balanceEntry = $balance['info'][$i];
                $currencyId = $this->exchange->safe_string($balanceEntry, 'currency');
                $code = $this->exchange->safe_currency_code($currencyId);
                $wallet = $this->exchange->safe_float($balanceEntry, 'walletBalance');
                $margin = $this->exchange->safe_float($balanceEntry, 'marginBalance');
                $unrealisedProfit = $this->exchange->safe_float(
                    $balanceEntry,
                    'unrealisedPnl'
                );
                
                if ('BTC' === $code) {
                    $balance['XBT'] = $balance['BTC'];
                    $balance['total']['XBT'] = $balance['total']['BTC'];
                    $balance['used']['XBT'] = $balance['used']['BTC'];
                    $balance['free']['XBT'] = $balance['free']['BTC'];
                    unset($balance['BTC']);
                    unset($balance['total']['BTC']);
                    unset($balance['used']['BTC']);
                    unset($balance['free']['BTC']);
                    $code = 'XBT';
                    if (null != $wallet) {
                        $wallet /= 100000000;
                    }
                    if (null != $margin) {
                        $margin /= 100000000;
                    }
                    if (null != $unrealisedProfit) {
                        $unrealisedProfit /= 100000000;
                    }
                }

                $balance[$code]['available'] = $balance[$code]['total'];
                $balance[$code]['current_margin'] = $balance[$code]['used'];
                $balance[$code]['wallet'] = $wallet;
                $balance[$code]['margin'] = $margin;
                $balance[$code]['unrealized_profit'] = $unrealisedProfit;
            }
             
            return new ExchangeBalanceCcxt($balance);
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * User income history
     *
     * @param string $symbol     ccxt symbol
     * @param string $incomeType incomeType
     * @param int    $from
     * @param int    $to
     * @param int    $limit
     * @return ExchangeIncome[]
     */
    public function income(string $symbol = null, string $incomeType = null, $from = null, $to = null, $limit = null)
    {
        $params = [
            // 'currency' => $symbol,
            // 'count' => $limit
        ];

        if (null == $limit) {
            // put max limit to get max data in only one call
            $limit = 500;
        }

        if ($incomeType != null) {
            $params['filter'] = [
                'execType' => $this->translateIncomeTypeToBitmex($incomeType)
            ];
        }
        $recordLimit = 100000;

        $ret = [];
        try {
            $lastTradeId = null;
            while (true) {
                $trades = $this->exchange->fetch_my_trades($symbol, $from, $limit, $params);
                $numTrades = count($trades);
                if ((0 === $numTrades)
                    || ($numTrades == 1 && $trades[0]['id'] == $lastTradeId)
                ) {
                    break;
                }
                foreach ($trades as $trade) {
                    if ($lastTradeId === $trade['id']) continue;
                    $lastTradeId = $trade['id'];
                    $from = $trade['timestamp'];
                    if ($recordLimit-- == 0) break;
                    $info = $this->exchange->safe_value($trade, 'info');
                    $ret[] = new ExchangeIncomeCcxt(
                        array(
                            'info' => $info,
                            'symbol' => $symbol,
                            'incomeType' => $this->translateIncomeTypeFromBitmex(
                                $this->exchange->safe_string($info, 'execType')
                            ),
                            'income' => $this->exchange->safe_float($trade['fee'], 'cost'),
                            'asset' => 'XBT',
                            'timestamp' => $trade['timestamp'],
                            'datetime' => $trade['datetime'],
                            'incomeInfo' => $this->exchange->safe_string($info, 'text'),
                            'tranId' => $trade['id'],
                            'tradeId' => $trade['id'],
                        )
                    );
                }
            }
            return $ret;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
        

/*

        $marketIdFilter = null;
        if ($symbol != null) {
            $market = $this->exchange->market($symbol);
            $marketIdFilter = $market['id'];
        }

        if (null === $limit) {
            //  max limit of 10000 records
            $limit = 10000;
        }
    
        try {
            $count = 0;
            $ret = array();
            $outOfBounds = false;
            $filterByType = null === $incomeType ? null : $this->translateIncomeTypeToBitmex($incomeType);
            while (!$outOfBounds && ($count < $limit)) {
                $incomeList = $this->exchange->privateGetUserWalletHistory($params);
                $incomeListCount = count($incomeList);
                if (0 === $incomeListCount) {
                    break;
                }
                foreach ($incomeList as $income) {
                    if ($count >= $limit) {
                        break;
                    }
                    $incomeTypeFromBitmex = $this->exchange->safe_string($income, 'transactType');
                    $timestamp = $this->exchange->parse8601(
                        $this->exchange->safe_string($income, 'transactTime')
                    );

                    if (null !== $filterByType && $filterByType !== $incomeTypeFromBitmex) {
                        continue;
                    }
                    if (null !== $to && $timestamp > $to) {
                        continue;
                    }
                    if (null !== $from && $timestamp < $from) {
                        // we receive records ordered from latest to first
                        // so if this timestamp is lower that $from stop
                        $outOfBounds = true;
                        break;
                    }
                    /*
                    (
                        [transactID] => 4904644d-125a-ed02-cd12-ddaecd064b0a
                        [account] => 322611
                        [currency] => XBt
                        [transactType] => RealisedPNL
                        [amount] => 217
                        [fee] => 0
                        [transactStatus] => Completed
                        [address] => XBTUSD
                        [tx] => 913a7bce-ec35-9720-d671-69c2af2573df
                        [text] =>
                        [transactTime] => 2020-10-10T12:00:00.000Z
                        [walletBalance] => 1000217
                        [marginBalance] =>
                        [timestamp] => 2020-10-10T12:00:00.010Z
                    )
                    (
                        [transactID] => bd4efe24-4651-3317-809f-c94852ee87f7
                        [account] => 322611
                        [currency] => XBt
                        [transactType] => Transfer
                        [amount] => 1000000
                        [fee] =>
                        [transactStatus] => Completed
                        [address] => 0
                        [tx] => b323a531-1647-8575-ae44-8e1fe61d8913
                        [text] => Signup bonus
                        [transactTime] => 2020-09-24T10:33:01.191Z
                        [walletBalance] => 1000000
                        [marginBalance] =>
                        [timestamp] => 2020-09-24T10:33:01.191Z

                    )
                    * /
                    $assetId = $this->exchange->safe_string($income, 'currency');
                    $asset = $this->exchange->safe_currency_code($assetId);
                    $marketId = $this->exchange->safe_string($income, 'address');
                    if (null !== $marketIdFilter && $marketIdFilter !== $marketId) {
                        continue;
                    }
                    $symbol = null;
                    $market = null;
                    if (is_array($this->exchange->markets_by_id) && array_key_exists($marketId, $this->exchange->markets_by_id)) {
                        $market = $this->exchange->markets_by_id[$marketId];
                    }
                    if ($market !== null) {
                        $symbol = $market['symbol'];
                    }
                    

                    $ret[] = new ExchangeIncomeCcxt(
                        array(
                            'info' => $income,
                            'symbol' => $symbol,
                            'incomeType' => $this->translateIncomeTypeFromBitmex(
                                $incomeTypeFromBitmex
                            ),
                            'income' => $this->exchange->safe_float($income, 'amount') / 100000000,
                            'asset' => 'BTC' === $asset ? 'XBT' : $asset,
                            'timestamp' => $timestamp,
                            'datetime' => $this->exchange->iso8601($timestamp),
                            'incomeInfo' => $this->exchange->safe_string($income, 'text'),
                            'tranId' => $this->exchange->safe_string($income, 'transactID'),
                            'tradeId' => 0,
                        )
                    );
                    $count++;
                }
                $params['start'] = $incomeListCount;
            }
            return $ret;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
        */
    }

    /**
     * @param BSONDocument $position
     * @param float $amount
     * @return float
     */
    public function transferMargin(BSONDocument $position, float $amount):float
    {
        $params = [
            'symbol' => $position->signal->pair,
            'amount' => $amount*(10**8) //In satoshis
        ];

        $result = $this->exchange->privatePostPositionTransferMargin($params);

        return $result['maintMargin']/(10 ** 8);
    }

    /**
     * Translate income type from bitmex
     *
     * @param string $incomeType income type from zignaly
     *
     * @return string
     */
    protected function translateIncomeTypeFromBitmex($incomeType)
    {
        if (array_key_exists($incomeType, $this->incomeTypes)) {
            return $this->incomeTypes[$incomeType];
        }
        return $incomeType;
    }
    /**
     * Translate income type to btimex
     *
     * @param string $incomeType income type from exchange
     *
     * @return string
     */
    protected function translateIncomeTypeToBitmex($incomeType)
    {
        $key = array_search($incomeType, $this->incomeTypes);
        if (false === $key) {
            return $incomeType;
        }

        return $key;
    }

    /**
     * Calculate fee for order
     *
     * @param ExchangeTrade $order
     *
     * @return ExchangeTradeFee
     */
    public function calculateFeeForTrade(ExchangeTrade $trade)
    {
        $symbol = $trade->getSymbol();
        $market = $this->exchange->market($symbol);
        $quote = $market['quote'];
        if ($trade->getTakerOrMaker() === ExchangeTradeMakerOrTaker::Maker) {
            $feeCost = -$trade->getCost() * 0.025;
        } else {
            $feeCost = $trade->getCost() * 0.075;
        }

        return new ExchangeTradeFeeCcxt($feeCost, $quote, null);
    }

    /**
     * User forced orders
     *
     * @param string $symbol
     * @param string $autoCloseType "LIQUIDATION" for liquidation orders, "ADL" for ADL orders.
     * @param int $from
     * @param int $to
     * @param int $limit
     * @return ExchangeOrder[]
     */
    public function forceOrders(string $symbol = null, string $autoCloseType = null, $from = null, $to = null, $limit = null)
    {
        if ('ADL' === $autoCloseType) {
            return [];
        }

        $params = [
            'symbol' => $symbol,
            //'startTime' => $from,
            'endTime' => $to,
            'filter' => ['text'=>'Liquidation']
            //'filter' => '{"side": "Buy"}'
        ];
        
        if ($symbol != null) {
            $market = $this->exchange->market($symbol);
            $params['symbol'] = $market['id'];
        }
    
        try {
            $tradeList = $this->exchange->fetchMyTrades($symbol, $from, $limit, $params);
            //['text'] = 'Liquidation'
            $ret = array();
            foreach ($tradeList as $trade) {
                $orderId = 'trade_'.$trade['id'];
                $trade['order'] = $orderId;
                $tradeObject = new ExchangeTradeCcxt($trade);
                $ret[] = new ExchangeOrderCcxt(
                    [
                        'info' => $trade,
                        'id' => $orderId,
                        'clientOrderId' => null,
                        'timestamp' => $tradeObject->getTimestamp(),
                        'datetime' => $tradeObject->getStrDateTime(),
                        'lastTradeTimestamp' => $tradeObject->getTimestamp(),
                        'symbol' => $tradeObject->getSymbol(),
                        'type' => $trade['type'],
                        'side' => $trade['side'],
                        'price' => $tradeObject->getPrice(),
                        'amount' => $tradeObject->getAmount(),
                        'cost' => $tradeObject->getCost(),
                        'average' => $tradeObject->getPrice(),
                        'filled' => $tradeObject->getAmount(),
                        'remaining' => 0,
                        'status' => 'closed',
                        'fee' => null,
                        'trades' => null,
                    ],
                    [$tradeObject]
                );
            }
            return $ret;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * @return bool
     */
    public function supportsMarginMode():bool
    {
        return true;
    }

    /**
     * @return float
     */
    public function getLeverageForCrossMargin():float
    {
        return 0.0;
    }

    /**
     * @param string $symbol
     * @param string $mode
     * @return mixed|void
     */
    public function setMarginMode(string $symbol, string $mode)
    {
        $market = $this->exchange->market($symbol);
        $rawSymbol = $market['id'];
        $params = [
            'symbol' => $rawSymbol,
            'enabled' => 'cross' !== $mode,
        ];

        $updateMarginMode = $this->exchange->privatePostPositionIsolate($params);

        $data = [
            "$rawSymbol" => [
                'marginMode' => $updateMarginMode['crossMargin'] ? 'cross' : 'isolated'
            ]
        ];

        return $data;
    }
    /**
     * Fix ccxt order status to set expired when
     *
     * @param array $ccxtOrder ccxt order to check
     * 
     * @return array
     */
    private function fixOrderStatus($ccxtOrder)
    {
        if (isset($ccxtOrder['info']['text'])) {
            if (false !== stripos(
                $ccxtOrder['info']['text'],
                'Canceled: Order had execInst of ParticipateDoNotInitiate'
            )
            ) {
                $ccxtOrder['status'] = 'expired';
            }
        }
        return $ccxtOrder;
    }
}
