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

use ccxt;
use Zignaly\exchange\ccxtwrap\BaseExchangeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeBalanceCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeDustTransferCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeIncomeCcxt;
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\exceptions;
use Zignaly\exchange\ExchangeOrderStatus;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeTradeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeTradeFeeCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeUserTransactionInfoCcxt;
use Zignaly\exchange\ccxtwrap\ExchangeWithdrawalCcxt;
use Zignaly\exchange\ExchangeIncome;
use Zignaly\exchange\ExchangeTrade;
use Zignaly\exchange\ExchangeTradeFee;
use Zignaly\exchange\marketencoding\BinanceMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;

class Binance extends BaseExchangeCcxt
{
    public function __construct(ExchangeOptions $options)
    {
        $options->ccxtOptions['options']['warnOnFetchOpenOrdersWithoutSymbol'] = false;
        parent::__construct("binance", $options);
    }

    /**
     * get exchange if (zignaly internal code)
     *
     * @return void
     */
    public function getId()
    {
        return ZignalyExchangeCodes::ZignalyBinance;
    }
    
    /**
     * Extract error from ccxt exception
     *
     * @param \Exception $ccxtException
     * @return array
     */
    protected function extractErrorFromCcxtException($ccxtException)
    {
        $message = str_replace('binance ', '', $ccxtException->getMessage());
        $ret = json_decode($message, true);
        if (null != $ret) {
            return $ret;
        }

        return [
            'code' => null,
            'msg'  => $message
        ];
    }

    /**
     * Extract error from ccxt exception
     *
     * @param \Exception $ccxtException
     * @return string
     */
    protected function extractErrorMsgFromCcxtException($ccxtException)
    {
        $error = $this->extractErrorFromCcxtException($ccxtException);
        return isset($error['msg']) ? $error['msg'] : $ccxtException->getMessage();
    }

    /**
     * Undocumented function
     *
     * @param \ccxt\BaseError $ccxtException
     * @param string $message
     *
     * @return void
     */
    protected function parseCcxtException($ccxtException, $message = "")
    {
        if (strrpos($ccxtException->getMessage(), "\"error\":{\"message\":\"Not Found\"") !== false) {
            return new exceptions\ExchangeOrderNotFoundException($message, 0, $ccxtException);
        } else if (strrpos($ccxtException->getMessage(), "Insufficient balance") !== false) {
            return new exceptions\ExchangeInsufficientFundsException($message, 0, $ccxtException);
        } else if (strrpos($ccxtException->getMessage(), "\"code\":-9000,\"msg\":\"Only can be requested once within") !== false) {
            return new exceptions\ExchangeLimitRateException($message, 0, $ccxtException);
        }

        return parent::parseCcxtException($ccxtException, $message);
    }

    /**
     * Prepare ccxt params from ExchangeExtraParams
     *
     * @param string $orderType
     * @param ExchangeExtraParams $params
     *
     * @return array
     */
    protected function prepareCreateOrderCcxtParams(string $orderType, ExchangeExtraParams $params = null)
    {
        $ps = array(
            "recvWindow" => 60000,
        );
        if (($params != null) && ($params->getStopPrice() != null)) {
            $ps["stopPrice"] = $params->getStopPrice();
        }

        if (($params != null) && ($params->getStopLossPrice() != null)) {
            $ps["stopPrice"] = $params->getStopLossPrice();
        }

        if (($params != null) && ($params->getQuoteOrderQty() != null)) {
            $ps["quoteOrderQty"] = $params->getQuoteOrderQty();
        }

        return $ps;
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
            // translate order type to binance
            $type = $this->translateOrderType2Binance($type);

            $ps = $this->prepareCreateOrderCcxtParams($orderType, $params);
            // add binance zignaly code to all
            $newClientOrderId = floor(microtime(true) * 1000);
            $ps['newClientOrderId'] = $this->getZignalyBrokerId().$newClientOrderId;
            /* binance has a limit of 36 chars for newClientOrderId so this is not valid
            if (null != $params) {
                $zignalyPositionId = $params->getZignalyPositionId();
                if (null != $zignalyPositionId) {
                    $ps['newClientOrderId'] = 'x-UOZU148D-'
                        .$zignalyPositionId.'-'.$newClientOrderId;
                }
            }
            */
            // Avoid to pass price to market orders due futures endpoint raise a request error.
            if ($type === 'MARKET') {
                $price = null;
            }

            $order = $this->exchange->create_order($symbol, $type, $side, $amount, $price, $ps);
            $order['type'] = $this->translateOrderTypeFromBinance($order['type']);
            $order['zignalyClientId'] = $ps['newClientOrderId'];
            $order['recvClientId'] = $this->exchange->safe_string($order['info'], 'clientOrderId');

            return new ExchangeOrderCcxt($order);
        } catch (\ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * order info
     *
     * @param ExchangeOrder $order
     *
     * @return ExchangeOrder
     */
    public function orderInfo(string $orderId, string $symbol = null)
    {
        try {
            $ps = array(
                "recvWindow" => 60000,
            );
            $order = $this->exchange->fetchOrder($orderId, $symbol, $ps);
            $order['type'] = $this->translateOrderTypeFromBinance($order['type']);
            $exchangeOrder = new ExchangeOrderCcxt($order);
            if (($exchangeOrder->getStatus() == ExchangeOrderStatus::Closed)
                || ($exchangeOrder->getStatus() == ExchangeOrderStatus::Canceled && $exchangeOrder->getFilled() > 0)
                || ($exchangeOrder->getStatus() == ExchangeOrderStatus::Expired && $exchangeOrder->getFilled() > 0)
            ) {
                $trades = array();
                $origInfo = $exchangeOrder->getInfo();

                $request = array(
                    //'startTime' => $exchangeOrder->getTimestamp() - 3600 * 1000, // 1 hour before
                    'startTime' => $origInfo['time'] - 3600 * 1000, // 1 hour before
                    "recvWindow" => 60000,
                );
                $currentOrderFill = 0;
                $targetOrderFill = $exchangeOrder->getFilled();
                for ($k = 0; $k < 2; $k++) {
                    $maxNumRequests = 4;
                    while ($maxNumRequests > 0) {
                        $myTradesResponse = $this->exchange->fetchMyTrades($symbol, null, 500, $request);
                        if (count($myTradesResponse) === 0) {
                            break;
                        }
                        foreach ($myTradesResponse as $tradeElement) {
                            // $tradeElement['type'] = $this->translateOrderTypeFromBinance ($tradeElement['type']);
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
                        //startTime and fromId are incompatible in futures fapi.
                        unset($request['startTime']);
                        $maxNumRequests--;
                    }

                    if ($currentOrderFill >= $targetOrderFill) {
                        break;
                    }

                    // search after update time, TODO: improve this
                    // the best way would be to search from time to updateTime
                    // but binance only let you to search within 7 days from startTime
                    // it woudl be really unlikely that trades could be in the middle of the period
                    // I guess all of them would be close to startTime, when market, or
                    // updateTime, when limit.
                    unset($request['fromId']);
                    $request['startTime'] = $origInfo['updateTime'] - 3600 * 1000;
                }
                $exchangeOrder->setTrades($trades);
            }

            return $exchangeOrder;
        } catch (\ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * Undocumented function
     *
     * @return ExchangeOrder[]
     */
    public function getClosedOrders($symbol = null, $since = null, $limit = null)
    {
        try {
            $ret = array();
            $orders = $this->exchange->fetchClosedOrders($symbol, $since, $limit);
            foreach ($orders as $order) {
                $order['type'] = $this->translateOrderTypeFromBinance($order['type']);
                $ret[] = new ExchangeOrderCcxt($order);
            }

            return $ret;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * Undocumented function
     *
     * @return ExchangeOrder[]
     */
    public function getOpenOrders($symbol = null, $since = null, $limit = null)
    {
        try {
            $ret = array();
            $orders = $this->exchange->fetchOpenOrders($symbol, $since, $limit);
            foreach ($orders as $order) {
                $order['type'] = $this->translateOrderTypeFromBinance($order['type']);
                $ret[] = new ExchangeOrderCcxt($order);
            }

            return $ret;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * Undocumented function
     *
     * @return ExchangeOrder[]
     */
    public function getOrders($symbol = null, $since = null, $limit = null)
    {
        try {
            $ret = array();
            $orders = $this->exchange->fetchOrders($symbol, $since, $limit);
            foreach ($orders as $order) {
                $order['type'] = $this->translateOrderTypeFromBinance($order['type']);
                $ret[] = new ExchangeOrderCcxt($order);
            }

            return $ret;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
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
        $feeCost = $trade->getCost() * 0.00075;

        return new ExchangeTradeFeeCcxt($feeCost, $quote, null);
    }

    /**
     * translate ccxt to expected to binance order type
     *
     * @param string $ccxtType
     *
     * @return string
     */
    protected function translateOrderType2Binance(string $ccxtType)
    {
        switch ($ccxtType) {
            case ExchangeOrderType::CcxtMarket:
                return "MARKET";
            case ExchangeOrderType::CcxtLimit:
                return "LIMIT";
            case ExchangeOrderType::CcxtStop:
                return "STOP_LOSS";
            case ExchangeOrderType::CcxtStopLimit:
                return "STOP_LOSS_LIMIT";
            case ExchangeOrderType::CcxtStopLossLimit:
                    return "STOP_LOSS_LIMIT";
            default:
                return $ccxtType;
        }
    }

    /**
     * translate binance order type (ccxt does not translate it) to our ccxt list
     *
     * @param string $binanceType
     *
     * @return string
     */
    protected function translateOrderTypeFromBinance(string $binanceType)
    {
        switch (strtolower($binanceType)) {
            case "limit":
                return ExchangeOrderType::CcxtLimit;
            case "market":
                return ExchangeOrderType::CcxtMarket;
            case "stop_loss":
                return ExchangeOrderType::CcxtStop;
            case "stop_loss_limit":
                return ExchangeOrderType::CcxtStopLimit;
            //case "TAKE_PROFIT":
            //case "TAKE_PROFIT_LIMIT":
            //case "LIMIT_MAKER":
            default:
                return ($binanceType == null) ? $binanceType : strtolower($binanceType);
        }
    }

    /**
     * withdraw
     *
     * @param string $code
     * @param float $amount
     * @param string $address
     * @param string $tag
     * @param string $network
     *
     * @return ExchangeWithdrawalCcxt
     */
    public function withdraw($code, $amount, $address, $tag = null, $network = null)
    {
        try {
            $params = array();
            if ($network != null) {
                $params["network"] = $network;
            }

            //CCXT by default add a name. Adding a name make the address to be stored as favorite, and there
            //is a limit of items that you can have favorite, so a new transfer outside the limits will fail.
            $params["name"] = "";

            return new ExchangeWithdrawalCcxt($this->exchange->withdraw($code, $amount, $address, $tag, $params));
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * Undocumented function
     *
     * @return ExchangeUserTransactionInfo
     */
    public function getUserTransactionInfo()
    {
        try {
            return new ExchangeUserTransactionInfoCcxt($this->exchange->sapiGetCapitalConfigGetall());
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * Undocumented function
     *  {
     *      "symbol": "LTCBTC",
     *      "price": "4.00000200"
     *  }
     *      OR
     *  [
     *      {
     *          "symbol": "LTCBTC",
     *          "price": "4.00000200"
     *      },
     *      {
     *          "symbol": "ETHBTC",
     *          "price": "0.07946600"
     *      }
     *  ]
     *
     * @param string $symbol
     *
     * @return string[]
     */
    public function getLastTicker($symbol = null)
    {
        try {
            /*
            $ticker = $this->exchange->fapiPublicGetTickerPrice($symbol);
            $arr = array();
            if ($symbol != null){
                $arr[$symbol] = $this->exchange->safe_string($ticker, 'price');
            } else {
                foreach($ticker as $tick){
                    $symbol = $this->exchange->safe_string ($tick, 'symbol');
                    if (array_key_exists($symbol, $this->exchange->markets_by_id)) {
                        $market = $this->exchange->markets_by_id[$symbol];
                        $symbol = $market['symbol'];
                    }
                    $arr[$symbol] =$this->exchange->safe_string($tick, 'price');
                }
            }
            return $arr;
            */

            if ($symbol != null) {
                $tickers = $this->exchange->fetch_tickers([$symbol]);
            } else {
                $tickers = $this->exchange->fetch_tickers();
            }
            foreach ($tickers as $ticker) {
                $symbol = $this->exchange->safe_string($ticker, 'symbol');
                $arr[$symbol] = $this->exchange->safe_string($ticker, 'last');
            }

            return $arr;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * transfer from coins to exchange internal coin to recover small balances
     *
     * @param string[] $assets
     *
     * @return ExchangeDustTransfer
     */
    public function dustTransfer($assets)
    {
        try {
            return new ExchangeDustTransferCcxt($this->exchange->sapiPostAssetDust(array('asset' => $assets)));
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException(
                $ex,
                $this->extractErrorMsgFromCcxtException($ex)
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getLeverage(string $symbol): \stdClass
    {
        $data = [];
        $marketEncoder = new BinanceMarketEncoder();
        $rawSymbol = $marketEncoder->fromCcxt($symbol);
        $leverage = 1;
        $maxLeverage = 125;
        $customLeverage = false;

        // Determine if custom leverage was customized.
        $accountDetails = $this->exchange->fapiPrivateGetAccount();
        if (is_array($accountDetails['positions'])) {
            $positions =& $accountDetails['positions'];
            $symbolPositionIndex = array_search($rawSymbol, array_column($positions, 'symbol'));
            if (false !== $symbolPositionIndex) {
                $symbolPosition = $positions[$symbolPositionIndex];
                if (isset($symbolPosition['leverage'])) {
                    $leverage = $symbolPosition['leverage'];
                    $customLeverage = true;
                }
            }
        }

        // Get leverage brackets for symbol
        // $leverageBracketsData = $this->exchange->fapiPublicGetLeverageBracket([
        $leverageBracketsData = $this->exchange->fapiPrivateGetLeverageBracket([
            'symbol' => $rawSymbol,
            'timestamp' => time() * 1000
        ]);

        // TODO: Handle more advanced errors return as future improvement.
        if (isset($leverageBracketsData['brackets']) && is_array($leverageBracketsData['brackets'])) {
            $leverageBrackets = $leverageBracketsData['brackets'];
            $maxLeverage = $leverageBrackets[0]['initialLeverage'];

            if (!$customLeverage) {
                // When data is available default leverage to bracket #4.
                $defaultBracketIndex = array_search(
                    4,
                    array_column($leverageBrackets, 'bracket')
                );
                if ($defaultBracketIndex) {
                    $leverage = $leverageBrackets[$defaultBracketIndex]['initialLeverage'];
                }
            }
        }

        $data[$rawSymbol] = [
            'leverage' => $leverage,
            'maxLeverage' => $maxLeverage
        ];

        return (object)$data;
    }

    /**
     * @inheritDoc
     */
    public function changeLeverage(string $symbol, int $leverage): \stdClass
    {
        $marketEncoder = new BinanceMarketEncoder();
        $rawSymbol = $marketEncoder->fromCcxt($symbol);
        $params = [
            'symbol' => $rawSymbol,
            'leverage' => $leverage,
        ];

        $updateLeverage = $this->exchange->fapiPrivatePostLeverage($params);

        $data = [
            "$rawSymbol" => [
                'leverage' => $updateLeverage['leverage'],
            ],
        ];

        return (object)$data;
    }

    /**
     * @inheritDoc
     *
     * @throws \Zignaly\exchange\exceptions\ExchangeOrderNotFoundException
     * @noinspection PhpMissingParentCallCommonInspection
     */
    public function balanceTransfer(string $symbol, float $amount, int $type): array
    {
        $params = [
            'asset' => $symbol,
            'amount' => $amount,
            'type' => $type,
        ];

        try {
            return $this->exchange->sapiPostFuturesTransfer($params);
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function balanceTransferHistory(string $asset, int $from): array
    {
        $params = [
            'asset' => $asset,
            'startTime' => $from,
            'size' => 100
        ];

        try {
            return $this->exchange->sapiGetFuturesTransfer($params);
        } catch (ccxt\BaseError $ex) {
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
     * User income history
     *
     * @param string $symbol
     * @param string $incomeType
     * @param int $from
     * @param int $to
     * @param int $limit
     * @return ExchangeIncome[]
     */
    public function income(string $symbol = null, string $incomeType = null, $from = null, $to = null, $limit = null)
    {
        $params = [
            'symbol' => $symbol,
            'incomeType' => $incomeType,
            'startTime' => $from,
            'endTime' => $to,
            'limit' => $limit
        ];
        
        if ($symbol != null) {
            $market = $this->exchange->market($symbol);
            $params['symbol'] = $market['id'];
        }
    
        try {
            $ret = [];
            $lastTranId = null;
            $lastTimestamp = $from;
            $maxRecordsEachResponse = 100;
            do {
                $incomeList = $this->exchange->fapiPrivateGetIncome($params);
                $recordsProcessed = 0;
                $recordsReceived = 0;
                foreach ($incomeList as $income) {
                    $recordsReceived++;
                    /*
                    (
                        [symbol] => BZRXUSDT
                        [incomeType] => COMMISSION
                        [income] => -0.05802630
                        [asset] => USDT
                        [time] => 1599407753000
                        [info] => 4153722
                        [tranId] => 10304153722
                        [tradeId] => 4153722
                    )
                    */
                    $timestamp = $this->exchange->safe_integer($income, 'time');
                    $tranId = $this->exchange->safe_integer($income, 'tranId');
                    if ($lastTranId == $tranId) {
                        continue;
                    }

                    $lastTimestamp = $timestamp;
                    $lastTranId = $tranId;
                    if ((null != $to) && ($lastTimestamp > $to)) {
                        break;
                    }
                    
                    $recordsProcessed += 1;
                    $assetId = $this->exchange->safe_string($income, 'asset');
                    $asset = $this->exchange->safe_currency_code($assetId);
                    $marketId = $this->exchange->safe_string($income, 'symbol');
                    $symbol = null;
                    $market = null;
                    if (is_array($this->exchange->markets_by_id) && array_key_exists($marketId, $this->exchange->markets_by_id)) {
                        $market = $this->exchange->markets_by_id[$marketId];
                    }
                    if ($market !== null) {
                        $symbol = $market['symbol'];
                    }
                    
                    $ret[] = new ExchangeIncomeCcxt(array(
                        'info' => $income,
                        'symbol' => $symbol,
                        'incomeType' => $this->exchange->safe_string($income, 'incomeType'),
                        'income' => $this->exchange->safe_float($income, 'income'),
                        'asset' => $asset,
                        'timestamp' => $timestamp,
                        'datetime' => $this->exchange->iso8601($timestamp),
                        'incomeInfo' => $this->exchange->safe_integer($income, 'info'),
                        'tranId' => $tranId,
                        'tradeId' => $this->exchange->safe_integer($income, 'tradeId'),
                    ));
                }
                $params['startTime'] = $lastTimestamp;
            } while (($recordsReceived >= $maxRecordsEachResponse)
                && ($recordsProcessed > 0)
                && ((null == $to) || ($lastTimestamp <= $to))
            );

            return $ret;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
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
        $params = [
            'symbol' => $symbol,
            'autoCloseType' => $autoCloseType,
            'startTime' => $from,
            'endTime' => $to,
            'limit' => $limit
        ];
        
        if ($symbol != null) {
            $market = $this->exchange->market($symbol);
            $params['symbol'] = $market['id'];
        }
    
        try {
            $orderList = $this->exchange->fapiPrivateGetForceOrders($params);
            $ret = array();
            foreach ($orderList as $order) {
                $ret[] = new ExchangeOrderCcxt($this->exchange->parse_order($order));
            }
            return $ret;
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
            $balance['max_withdraw_amount'] = array();
            $assets = $this->exchange->safe_value($balance['info'], 'assets', array());
            for ($i = 0; $i < count($assets); $i++) {
                $balanceEntry = $assets[$i];
                /*                
                    [asset] => USDT
                    [walletBalance] => 100000.00000000
                    [unrealizedProfit] => 0.00000000
                    [marginBalance] => 100000.00000000
                    [maintMargin] => 0.00000000
                    [initialMargin] => 0.00000000
                    [positionInitialMargin] => 0.00000000
                    [openOrderInitialMargin] => 0.00000000
                    [maxWithdrawAmount] => 100000.00000000
                    [crossWalletBalance] => 100000.00000000
                    [crossUnPnl] => 0.00000000
                    [availableBalance] => 100000.00000000
                */
                $currencyId = $this->exchange->safe_string($balanceEntry, 'asset');
                $code = $this->exchange->safe_currency_code($currencyId);
                if (isset($balance[$code])) {
                    $maxWithdrawAmount = $this->exchange->safe_float(
                        $balanceEntry,
                        'maxWithdrawAmount',
                        $balance[$code]['total']
                    );
                    $balance[$code]['max_withdraw_amount'] = $maxWithdrawAmount;
                    $balance['max_withdraw_amount'][$code] = $maxWithdrawAmount;
                }

            }
            return new ExchangeBalanceCcxt($balance);
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }
    /**
     * Get zignaly broker id for spot
     *
     * @return string
     */
    protected function getZignalyBrokerId(): string
    {
        return 'x-KMFNJ58X'; // old id 'x-UOZU148D';
    }
}
