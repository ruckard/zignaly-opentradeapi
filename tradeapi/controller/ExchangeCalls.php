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


use MongoDB\Model\BSONDocument;
use Symfony\Component\HttpFoundation\JsonResponse;
use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeFactory;
use Zignaly\exchange\ExchangeFuturesTransfer;
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\ExchangeOrder;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\exchange\ExchangeDepositAddress;
use Zignaly\exchange\ExchangeDustTransfer;
use Zignaly\exchange\ExchangeIncome;
use Zignaly\exchange\ExchangeTransaction;
use Zignaly\exchange\ExchangeUserTransactionInfo;
use Zignaly\exchange\ExchangeWithdrawal;
use Zignaly\exchange\papertrade\PaperTradeExchange;
use Zignaly\papertrading\PaperTradingEngineFactory;
use Zignaly\Process\DIContainer;
use Zignaly\redis\ZignalyLastPriceRedisService;
use Zignaly\service\ZignalyLastTradesService;
use Zignaly\service\ZignalyMarketDataService;
use Zignaly\utils\PositionUtils;
use Zignaly\exchange\ExchangeIncomeType;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\Mediator\ExchangeHandler\ExchangeHandler;
use Zignaly\Mediator\PositionMediator;

class ExchangeCalls
{
    private $exchangeAuth = false;
    /** @var BaseExchange */
    private $exchangeClass;
    private $exchangeName;
    private $HistoryDB;
    private $lastPrice = false;
    private $Monolog = false;
    private $RedisHandlerLastPrices;
    /** @var ZignalyLastPriceRedisService */
    private $lastPriceService;
    /** @var ZignalyMarketDataService */
    private $marketDataService;
    private $Security;
    private $symbolInfo = false;
    private $substitutions = false;
    private $createdExchangesClass = [];
    private $debug = false;
    /** @var newUser */
    private $newUser;
    private $exchangeAccountType = false;
    private $TradeApiClient;
    private $exchangeIsPaperTrading = false; //Todo: temporal solution.
    /**
     * Restart worker instance.
     *
     * @var RestartWorker
     */
    private $RestartWorker;

    private $ccxtConfig;
    /** @var ZignalyLastTradesService */
    private $lastTradesProvider;

    /**
     * Process memory cache.
     *
     * @var object|\Symfony\Component\Cache\Adapter\ArrayAdapter|null
     */
    private $arrayCache;

    /**
     *Order model class
     *
     * @var Order $Order
     */
    private $Order;

    public function __construct(Monolog $Monolog)
    {
        global $ccxtExchangesGlobalConfig;
        $container = DIContainer::getContainer();
        // Workaround until we inject all Monolog instances through DI.
        if (!$container->has('monolog')) {
            $container->set('monolog', $Monolog);
        }

        $this->Monolog = $Monolog;
        $this->Security = $container->get('exchange.security');
        $this->RedisHandlerLastPrices = $container->get('recentHistoryPrices');
        $this->lastPriceService = $container->get('lastPrice');
        $this->marketDataService = $container->get('marketData');
        $this->HistoryDB = $container->get('allHistoryPrices.storage.read');
        $this->newUser = $container->get('newUser.model');
        $this->TradeApiClient = $container->get('TradeApiClient');
        $this->ccxtConfig = $ccxtExchangesGlobalConfig;
        $this->lastTradesProvider = $container->get('recentHistoryPrices');
        $this->arrayCache = $container->get('arrayCache');
        $this->RestartWorker = $container->get('restartWorker');
        $this->Order = $container->get('order.model');

        $this->arrayCache->clear();
    }

    /**
     * We try to load the market data for the symbol, if it's success then we can consider that the symbol exists
     * for the exchange.
     *
     * @param string $symbol
     * @return bool
     */
    public function checkIfSymbolExistsInExchange(string $symbol)
    {
        return $this->updateSymbolInfo($symbol);
    }

    /*private function checkIfTradeAlreadyExists($trades, $tradeId)
    {
        if (!$trades) {
            return false;
        }

        foreach ($trades as $trade) {
            if (isset($trade->id) && $trade->id == $tradeId) {
                return true;
            }
        }

        return false;
    }*/

    public function checkIfValueIsGood($limit, $type, $value, $market)
    {
        // @luis I leave this old call in case this is used later in another method
        $this->updateSymbolInfo($market); //Todo: use TradeApiCall for updating symbols info.
        // @luis Bitmex inverse limits seem to be flipped cost/amount so this method 
        // returns true on XBTUSD market because limits['amount']['min|max'] is null
        // and stoploss process does NOT close the position
        // so I move this code to ExchangeHandler classes 
        $exchangeHandler = ExchangeHandler::newInstance($this->exchangeName, $this->exchangeAccountType);
        if ($exchangeHandler->checkIfValueIsGood($limit, $type, $value, $market)) {
            return true;
        } else {
            if ($this->Monolog) {
                $this->Monolog->sendEntry('debug', "Check for $limit ($value) is not good for $type ($market).");
            }
            return false;
        }
    }

    public function enableDebug()
    {
        $this->debug = true;
    }

    /**
     * Round amount to specific transfer network currency precision.
     *
     * @param string $currencyCode Symbol code.
     * @param string $network Transfer network to round for.
     * @param float $amount Amount to round.
     *
     * @return float Amount with adjusted precision or original value if precision failed.
     */
    public function getAmountToCurrencyPrecision(string $currencyCode, string $network, float $amount)
    {
        if ($amount == 0) {
            $this->Monolog->sendEntry('DEBUG', 'Given amount for precision is 0, nothing to do.');
            return false;
        }


        try {
            $amountWithPrecision = $this->exchangeClass->withdrawCurrencyNetworkPrecision($currencyCode, $network, $amount);
            if (empty($amountWithPrecision)) {
                $this->Monolog->sendEntry(
                    'WARNING',
                    sprintf(
                        "Amount %s retrieved precision is empty = %s using %s symbol",
                        $amount,
                        $amountWithPrecision,
                        $currencyCode,
                    )
                );
            }

            return $amountWithPrecision;
        } catch (\Exception $e) {
            $this->Monolog->sendEntry('warning', "Couldn't get the amount currency precision ($currencyCode): " . $e->getMessage());

            return $amount;
        }
    }

    public function getAmount4Market($positionSize, $limitPrice, $symbol)
    {
        // TODO: pending to be placed in wrapper classes
        if ($this->exchangeName == "bitmex") {
            return $positionSize;
        } else {
            return $positionSize / $limitPrice;
        }
    }

    /**
     * Round amount to specific symbol (currency pair) precision.
     *
     * @param $amount Amount to round.
     * @param $symbol Symbol code.
     *
     * @return bool|float
     */
    public function getAmountToPrecision($amount, $symbol)
    {
        if ($amount == 0) {
            $this->Monolog->sendEntry('DEBUG', 'Given amount for precision is 0, nothing to do.');
            return false;
        }

        $market = 'Unknown Market';

        try {
            $market = $this->symbolParamToCcxt($symbol);
            // Ensure symbol exists in Exchange.
            $this->exchangeClass->market($market);
            $amountWithPrecision = $this->exchangeClass->amountToPrecision($market, $amount);

            if (empty($amountWithPrecision)) {
                $this->Monolog->sendEntry(
                    'WARNING',
                    sprintf(
                        "Amount %s retrieved precision is empty = %s at %s market and %s symbol",
                        $amount,
                        $amountWithPrecision,
                        $market,
                        $symbol
                    )
                );
            }

            return $amountWithPrecision;
        } catch (\Exception $e) {
            $this->Monolog->sendEntry('warning', "Couldn't get the amount ($market): " . $e->getMessage());

            return false;
        }
    }

    /**
     * Undocumented function
     *
     * @param string $orderId
     * @param object position
     * @return ExchangeOrder | array
     */
    public function exchangeCancelOrder(string $orderId, $position)
    {
        try {
            $exchangeId = isset($position->exchange->internalId) ? $position->exchange->internalId : $position->exchange->_id->__toString();
            $isInternalExchangeId = isset($position->exchange->internalId);
            $positionUserId = empty($position->profitSharingData) ? $position->user->_id : $position->profitSharingData->exchangeData->userId;
            $positionExchangeInternalId = empty($position->profitSharingData) ? $exchangeId : $position->profitSharingData->exchangeData->internalId;

            $this->reConnectExchangeWithKeys($positionUserId, $positionExchangeInternalId, $isInternalExchangeId);
            if (!$this->exchangeAuth) {
                $this->Monolog->sendEntry('warning', "Couldn't connect from data on position: " . $position->_id->__toString());
                return [
                    'error' => 'Invalid API key/secret pair',
                ];
            }

            $symbol = $this->symbolParamToCcxt(
                $position->signal->pair,
                $position->signal->base,
                $position->signal->quote
            );

            $order = $this->exchangeClass->cancelOrder($orderId, $symbol);
            if ($order instanceof ExchangeOrderCcxt) {
                $cancelError = is_array($order->getCcxtResponse()) ? $order->getCcxtResponse() : [$order->getCcxtResponse()];
                $this->Monolog->sendEntry('info', "Cancel order returns: ", $cancelError);
            } else {
                if (isset($order['error']) && false !== strpos(strtolower($order['error']), 'Unknown order sent')) {
                    //Todo: Probably the order is already canceled but the fucking rest api doesn't respond on time, once
                    //we start storing the orders from the stream, we could check in our own db. In the meantime, we call
                    // the shitty again.
                    $unknownOrder = [$order];
                    $this->Monolog->sendEntry('debug', "Getting non-instance of exchangeOrderCcxt", $unknownOrder);
                    $order = $this->getOrder($position, $orderId);

                    if (!$order instanceof ExchangeOrderCcxt) {
                        $this->Monolog->sendEntry('debug', "Cancel order $orderId failed.", $order);
                        return $order;
                    }
                }
            }

            if ('open' !== strtolower($order->getStatus())) {
                $canceledOrder = $this->getOrder($position, $orderId);
                if ($canceledOrder instanceof  ExchangeOrderCcxt && 'open' !== strtolower($canceledOrder->getStatus())) {
                    return $canceledOrder;
                } else {
                    return $order;
                }
            } else {
                $this->Monolog->sendEntry('critical', "Order status is still open");
                return ['error' => 'Order still opened'];
            }
        } catch (\Exception $ex) {
            $this->Monolog->sendEntry('error', "Couldn't cancel the order $orderId: " . $ex->getMessage());

            if (false !== stripos($ex->getMessage(), 'Invalid API-key, IP') && 'Zignaly' !== $position->exchange->name) {
                $this->Monolog->sendEntry('critical', "Closing all positions because wrong api keys");
                $newPositionCCXT = new newPositionCCXT();
                $newPositionCCXT->closeAllPositionsBecauseWrongKey($position);
            }

            $order = $this->composeErrorFromException($ex);
            if (isset($order['error']) && (false !== stripos($order['error'], 'Unknown order sent')
                || false !== stripos($order['error'], 'Order does not exist')
                || false !== stripos($order['error'], 'order_not_exist_or_not_allow_to_cancel'))) {
                $order = $this->getOrder($position, $orderId);
            }
            if (!$order instanceof ExchangeOrderCcxt) {
                $this->Monolog->sendEntry('debug', "Cancel order $orderId failed: " . $order['error']);
            }
            return $order;
        }
    }

    /**
     * Cancel an order directly.
     *
     * @param string $orderId
     * @param \MongoDB\BSON\ObjectId $userId
     * @param string $exchangeInternalId
     * @param string $symbol
     * @return array|ExchangeOrder
     */
    public function cancelOrderDirectly(string $orderId, \MongoDB\BSON\ObjectId $userId, string $exchangeInternalId, string $symbol)
    {
        $this->reConnectExchangeWithKeys($userId, $exchangeInternalId, true);
        if (!$this->exchangeAuth) {
            $this->Monolog->sendEntry('warning', "Couldn't connect to exchange");
            return [
                'error' => 'Invalid API key/secret pair',
            ];
        }

        return $this->exchangeClass->cancelOrder($orderId, $symbol);
    }

    /**
     * Undocumented function
     *
     * @param string $symbol
     * @param string $orderType
     * @param string $orderSide
     * @param float $amount
     * @param float|null $price
     * @return ExchangeOrder
     */
    public function exchangeCreateOrder(
        string $symbol,
        string $orderType,
        string $orderSide,
        float $amount,
        $price,
        ExchangeExtraParams $params = null,
        $positionId = false
    ) {
        return $this->exchangeClass->createOrder(
            $symbol,
            strtolower($orderType),
            strtolower($orderSide),
            $amount,
            $price,
            $params,
            $positionId
        );
    }

    /**
     * Undocumented function
     *
     * @param string $orderId
     * @param string $symbol
     * @return ExchangeOrder
     */
    public function exchangeOrderStatus(string $orderId, string $symbol = null)
    {
        /*$order = $this->Order->getOrder($this->exchangeName, $this->exchangeAccountType, $orderId);
        if ($order) {
            return new ExchangeOrderCcxt($order);
        }*/
        return $this->exchangeClass->orderInfo($orderId, $symbol);
    }

    public function getTrades($position, $orderId = false, $order = false, $forceRestApi = false)
    {
        if (!$orderId && !$order) {
            return false;
        } elseif (!$order) {
            $order = $this->getOrder($position, $orderId, $forceRestApi);
        }
        // check if order received or passed to function are OK
        if (is_array($order) && array_key_exists('error', $order)) {
            $this->Monolog->sendEntry('debug', "Error getting order order $orderId: " . $order['error']);
            return false;
        }

        $newTrades = [];

        $orderId = $order->getId();
        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
        $zigId = $positionMediator->getSymbol();

        $try = 0;
        do {
            $trades = $order->getTrades();
            $tradesIds = [];
            if (is_array($trades)) {
                $tradesAmount = 0;
                foreach ($trades as $trade) {
                    $tradeId = $trade->getId() . $trade->getOrderId();
                    if (in_array($tradeId, $tradesIds)) {
                        continue;
                    }
                    $tradesIds[] = $tradeId;

                    $tradeCost = $trade->getCost();
                    if (null == $tradeCost) {
                        $tradeCost = $exchangeHandler->calculateOrderCostZignalyPair(
                            $zigId,
                            $trade->getAmount(),
                            $trade->getPrice()
                        );
                    }
                    $newTrade = [
                        "symbol" => $trade->getSymbol(),
                        "id" => $trade->getId(),
                        "orderId" => $trade->getOrderId(),
                        "orderListId" => -1,
                        "price" => $trade->getPrice(),
                        "qty" => $trade->getAmount(),
                        "cost" => $tradeCost,
                        "quoteQty" => 0,
                        "commission" => $trade->getFeeCost(),
                        "commissionAsset" => $trade->getFeeCurrency(),
                        "time" => $trade->getTimestamp(),
                        "isBuyer" => $order->getSide() == 'buy',
                        "isMaker" => $trade->isMaker(),
                        "isBestMatch" => null
                    ];
                    $newTrades[] = $newTrade;
                    $tradesAmount += $trade->getAmount();
                }
                //if (!empty($newTrades) && (float)$tradesAmount !== (float)$order->getFilled()) {
                $amountDifference = round((float)$tradesAmount - (float)$order->getFilled(), 8);
                if (!empty($newTrades) && abs($amountDifference) >= PHP_FLOAT_EPSILON) {
                    $this->Monolog->sendEntry('critical', "Wrong trades amount for order {$order->getId()}: {$order->getFilled()}/$tradesAmount, Difference: $amountDifference");
                    $order = $this->getOrder($position, $orderId);
                    if (is_array($order) && array_key_exists('error', $order)) {
                        $this->Monolog->sendEntry('debug', "Error getting order order $orderId: " . $order['error']);
                        return false;
                    }
                    if (1 === $try && abs($amountDifference) >= PHP_FLOAT_EPSILON) {
                        $extraTrade = $newTrades[0];
                        $extraTrade['id'] = '00000001';
                        $extraTrade['price'] = $order->getPrice();
                        $extraTrade['qty'] = $order->getAmount() - $tradesAmount;
                        $extraTrade['cost'] = $exchangeHandler->calculateOrderCostZignalyPair($zigId, $extraTrade['qty'], $extraTrade['price']);
                        $extraTrade['commission'] = $extraTrade['qty'] * $newTrades[0]['commission'] / $newTrades[0]['qty'];
                        $extraTrade['note'] = 'Extra fake trade for substituting the missing ones.';
                        $newTrades[] = $extraTrade;
                        $this->Monolog->sendEntry('debug', "Fake trade for fixing it: ", $extraTrade);
                    }
                    $try++;
                } elseif (!empty($newTrades)) {
                    return $newTrades;
                } else {
                    $try++;
                }
            } else {
                $this->Monolog->sendEntry('debug', "No trades found for order $orderId");
                return false;
            }
        } while ($try < 2);

        return $newTrades;
    }

    public function getAllPairsBalance()
    {
        try {
            $balanceData = $this->exchangeClass->fetchBalance();
            return $balanceData->getAll();
        } catch (\Exception $ex) {
            $this->Monolog->sendEntry('error', "Couldn't get balance: " . $ex->getMessage());
            return false;
        }
    }

    public function getBalance($user, $exchangeId, $quote, $type = 'all', $isInternalExchangeId = false)
    {
        if (!$isInternalExchangeId && (!isset($user->exchange) || !$user->exchange)) {
            return 0;
        }

        //$this->Monolog->sendEntry('debug', "Exchange data looks good");

        if (!$this->reConnectExchangeWithKeys($user->_id, $exchangeId, $isInternalExchangeId)) {
            $this->Monolog->sendEntry(
                'warning',
                'Error fetching data',
                ['error' => 'Couldn\'t connect with this secret/key pair.']
            );
            return 0;
        }

        if (!$this->exchangeAuth) {
            $this->Monolog->sendEntry('warning', "Couldn't connect from data on user: " . $user->_id->__toString());

            return 0;
        }

        try {
            $balanceData = $this->exchangeClass->fetchBalance();
        } catch (\Exception $ex) {
            $this->Monolog->sendEntry('error', "Couldn't get balance: " . $ex->getMessage());
            return 0;
        }

        $balanceData = $balanceData->getAll();
        if ($quote == 'all') {
            return $balanceData;
        }

        $marketEncoder = BaseMarketEncoder::newInstance($this->exchangeName, $this->exchangeAccountType);
        $translatedQuote = $marketEncoder->translateAsset($quote);
        foreach ($balanceData as $coin => $data) {
            if ($coin == $translatedQuote) {
                $free = number_format($data['free'], 12, '.', '');
                $locked = $data['total'] > 0 ? $data['total'] - $free : $data['used'];
                $locked = number_format($locked, 12, '.', '');
                $total = number_format($free + $locked, 12, '.', '');

                $this->Monolog->sendEntry('debug', "Coin: $coin, Free: $free, Locked: $locked, Total: $total", $data);

                if ($type == 'locked') {
                    return $locked;
                } elseif ($type == 'free') {
                    return $free;
                } else {
                    return [$total, $free];
                }
            }
        }

        if (!is_array($balanceData)) {
            $balanceData = ['data' => $balanceData];
        }

        $this->Monolog->sendEntry('debug', "Quote $quote not found", $balanceData);

        return 0;
    }

    /**
     * Get last price for a given symbol in the current active exchange.
     *
     * @param $symbol Symbol to look price for.
     * @param bool $force
     *
     * @return float
     */
    public function getLastPrice($symbol, $force = false)
    {
        $this->updateLastPrice($symbol, $force);

        return empty($this->lastPrice) ? null : $this->lastPrice;
    }

    /**
     * Given an error message return the level of such error for logging.
     *
     * @param string $error
     * @return string
     */
    private function getLogMethodFromError(string $error)
    {
        $error = strtolower($error);

        $knownErrors = [
            ['msg' => 'insufficient balance', 'method' => 'debug'],
            ['msg' => 'balance insufficient', 'method' => 'debug'],
            ['msg' => 'order does not exist', 'method' => 'warning'],
            ['msg' => 'binance temporary banned', 'method' => 'debug'],
            ['msg' => 'margin is insufficient', 'method' => 'warning'],
            ['msg' => 'this action disabled is on this account', 'method' => 'warning'],
        ];

        foreach ($knownErrors as $knownError) {
            if (strpos($error, strtolower($knownError['msg'])) !== false) {
                return $knownError['method'];
            }
        }

        return 'error';
    }

    /**
     * Get the order from position and retry if it fails.
     *
     * @param BSONDocument $position
     * @param string $orderId
     * @param bool $forceRestApi
     * @return array|ExchangeOrder
     */
    public function getOrder(BSONDocument $position, string $orderId, $forceRestApi = false)
    {
        if (!$forceRestApi) {
            //We look for the order in our DB.
            $orderFromDb = $this->Order->getOrder($this->exchangeName, $this->exchangeAccountType, $orderId, $position->signal->pair);
            if (!empty($orderFromDb['status']) && 'open' !== $orderFromDb['status']) {
                //If we found it and the status is not open, we return it
                //$this->Monolog->sendEntry('debug', "Found order in DB for {$position->signal->pair}");
                return new ExchangeOrderCcxt($orderFromDb);
            }
        }

        //If it's not found or the status is open, then we ask, just in case to the exchange for the order.
        $order = $this->fetchOrder($position, $orderId);
        if ($order instanceof ExchangeOrderCcxt) {
            //If it's an instance of order, then we return the order from the exchange.
            return $order;
        } elseif (!empty($orderFromDb['status'])) {
            //If it's not an instance from order, then we return the order from the db, only if we found it.
            return new ExchangeOrderCcxt($orderFromDb);
        } else {
            //If order wasn't an instance of orderccxt and order wasn't found in the exchange, then we return the error.
            return $order;
        }
    }

    /**
     * Get the order from a position.
     *
     * @param BSONDocument $position
     * @param string $orderId
     * @return array|ExchangeOrder
     */
    private function fetchOrder(BSONDocument $position, string $orderId)
    {
        try {
            $exchangeId = isset($position->exchange->internalId) ? $position->exchange->internalId : $position->exchange->_id->__toString();
            $isInternalExchangeId = isset($position->exchange->internalId);

            $positionUserId = empty($position->profitSharingData) ? $position->user->_id : $position->profitSharingData->exchangeData->userId;
            $positionExchangeInternalId = empty($position->profitSharingData) ? $exchangeId : $position->profitSharingData->exchangeData->internalId;

            $this->reConnectExchangeWithKeys($positionUserId, $positionExchangeInternalId, $isInternalExchangeId);
            if (!$this->exchangeAuth) {
                $this->Monolog->sendEntry('warning', "Couldn't connect from data on position: " . $position->_id->__toString());
                return [
                    'error' => 'Invalid API key/secret pair',
                ];
            }

            $symbol = $this->symbolParamToCcxt(
                $position->signal->pair,
                $position->signal->base,
                $position->signal->quote
            );
            //$this->Monolog->sendEntry('info', "Returning order for symbol $symbol (" . $position->signal->pair . ")");

            $order = $this->exchangeOrderStatus($orderId, $symbol);

            return $order;
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * Get the list of forced orders for a given symbol from a position.
     *
     * @param BSONDocument $position
     * @return array|ExchangeOrder[]
     */
    public function getForceOrders(BSONDocument $position)
    {
        try {
            $exchangeId = isset($position->exchange->internalId) ? $position->exchange->internalId : $position->exchange->_id->__toString();
            $isInternalExchangeId = isset($position->exchange->internalId);

            $positionUserId = empty($position->profitSharingData) ? $position->user->_id : $position->profitSharingData->exchangeData->userId;
            $positionExchangeInternalId = empty($position->profitSharingData) ? $exchangeId : $position->profitSharingData->exchangeData->internalId;

            $this->reConnectExchangeWithKeys($positionUserId, $positionExchangeInternalId, $isInternalExchangeId);
            if (!$this->exchangeAuth) {
                $this->Monolog->sendEntry('warning', "Couldn't connect from data on position: " . $position->_id->__toString());
                return [
                    'error' => 'Invalid API key/secret pair',
                ];
            }

            $symbol = $this->symbolParamToCcxt(
                $position->signal->pair,
                $position->signal->base,
                $position->signal->quote
            );
            //$this->Monolog->sendEntry('info', "Returning order for symbol $symbol (" . $position->signal->pair. ")");

            $fromDate = empty($position->buyPerformedAt) ? $position->createdAt->__toString() : $position->buyPerformedAt->__toString();
            $toDate = empty($position->closedAt) ? null : $position->closedAt->__toString();
            if ($fromDate > time() * 1000) {
                return [];
            }

            if (null !== $toDate && $fromDate > $toDate) {
                return [];
            }
            $orders = $this->exchangeClass->forceOrders($symbol, null, $fromDate, $toDate);

            return $orders;
        } catch (\Exception $ex) {
            $this->Monolog->sendEntry('error', 'Error getting forced orders for symbol ' .
                $position->signal->pair . ':' . $ex->getMessage());
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * Check the if there is any error and if so, if it's a order doesn't exist, try again a couple of times.
     *
     * @param array|object|bool $order
     * @param bool|int $tryAgain
     * @return bool|int
     */
    private function getTryAgain($order, $tryAgain)
    {
        if (is_object($order)) {
            return false;
        }

        if (!isset($order['error'])) {
            return false;
        }

        if ($tryAgain > 2) {
            return false;
        }

        if (strpos(strtolower($order['error']), 'order does not exist') !== false) {
            $this->Monolog->sendEntry('debug', "Order does not exist try $tryAgain");
            $tryAgain++;
            $this->exchangeAuth = false;
            sleep(1);

            return !$tryAgain ? 1 : $tryAgain++;
        }

        if (strpos(strtolower($order['error']), 'does not have market symbol') !== false) {
            $this->Monolog->sendEntry('critical', "Market symbol not found, sending processes restart");
            $this->RestartWorker->prepareAllProcessedForRestarting();
        }

        return false;
    }

    /**
     * fetchDepositAddress. You must do a setExchange and reConnectExchangeWithKeys
     *
     * @param string $code
     * @param string $network
     * @return ExchangeDepositAddress
     */
    public function fetchDepositAddress($code, $network = null)
    {
        try {
            return $this->exchangeClass->fetchDepositAddress($code, $network);
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * withdraw. You must do a setExchange and reConnectExchangeWithKeys
     *
     * @param string $currencyCode Currency code.
     * @param float $amount Amount to transfer.
     * @param string $address Target wallet addres.
     * @param string $tag MEMO/Tag network reference.
     * @param string $network Transfer network ID.
     *
     * @return ExchangeWithdrawal
     */
    public function withdraw($currencyCode, $amount, $address, $tag = null, $network = null)
    {
        try {
            $amountWithPrecision = $this->getAmountToCurrencyPrecision($currencyCode, $network, $amount);
            return $this->exchangeClass->withdraw($currencyCode, $amountWithPrecision, $address, $tag, $network);
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * deposit history. You must do a setExchange and reConnectExchangeWithKeys
     *
     * @param string|null $code
     * @param string|null $since
     * @return ExchangeTransaction[]
     */
    public function getDepositHistory(string $code = null, string $since = null): array
    {
        try {
            return $this->exchangeClass->fetchDeposits($code, $since);
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * deposit history. You must do a setExchange and reConnectExchangeWithKeys
     *
     * @param string $code
     * @return ExchangeTransaction[]
     */
    public function getWithdrawalHistory($code = null, $since = null)
    {
        try {
            return $this->exchangeClass->fetchWithdrawals($code, $since);
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * user transaction info . You must do a setExchange and reConnectExchangeWithKeys
     *
     * @return ExchangeUserTransactionInfo
     */
    public function getUserTransactionInfo()
    {
        try {
            return $this->exchangeClass->getUserTransactionInfo();
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * get last ticker
     *
     * @param string $symbol
     * @return string[]
     */
    public function getLastTicker($symbol = null)
    {
        try {
            return $this->exchangeClass->getLastTicker($symbol);
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * dust transfer
     *
     * @param string[] $assets
     * @return ExchangeDustTransfer
     */
    public function doDustTransfer($assets)
    {
        try {
            return $this->exchangeClass->dustTransfer($assets);
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * Balance transfer between exchange wallets.
     *
     * @param string $symbol Asset symbol to transfer balance for.
     * @param float $amount Amount to transfer.
     * @param int $type Transfer type, 1 (spot to futures) or 2 (futures to spot).
     *
     * @return array
     */
    public function balanceTransfer(string $symbol, float $amount, int $type): array
    {
        try {
            return $this->exchangeClass->balanceTransfer($symbol, $amount, $type);
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * Check which kind of signals is this and get the proper price based on it and user preferences.
     *
     * @param string $pair
     * @param array $provider
     * @param \MongoDB\BSON\ObjectId $exchange
     * @param bool|string $orderType
     * @param bool|float $limitPrice
     * @param bool|float $price
     * @return bool|float
     */
    public function getPrice(string $pair, array $provider, BSONDocument $exchange, $orderType, $limitPrice, $price)
    {
        try {
            $marketEncoder = BaseMarketEncoder::newInstance($this->exchangeName, $this->exchangeAccountType);
            $market = $marketEncoder->withoutSlash($pair);
            $priceDeviation = empty($exchange->priceDeviation) ? 1 : $exchange->priceDeviation;

            if ('market' === strtolower($orderType)) {
                $this->updateLastPrice($market);
                $priceFromSignal = $this->lastPrice * $priceDeviation;
            } elseif ((!empty($provider['isCopyTrading']) || !empty($provider['limitPriceFromSignal'])) && !empty($limitPrice)) {
                $priceFromSignal = $limitPrice;
            } else {
                $basePrice = !empty($price) ? $price : false;
                if (!$basePrice) {
                    $this->updateLastPrice($market);
                    $basePrice = $this->lastPrice;
                }
                $priceFromSignal = $basePrice * $priceDeviation;
            }

            if ($this->Monolog) {
                $this->Monolog->sendEntry('debug', "Price from signal: " .
                    number_format($priceFromSignal, 12, '.', '') . " ($priceFromSignal)");
            }

            return $this->getPriceToPrecision($priceFromSignal, $market); //This was "pair" instead of "market" check that it works well.
        } catch (\Exception $e) {
            $this->Monolog->sendEntry('error', "Couldn't get the price for $market ({$market}): " . $e->getMessage());

            return false;
        }
    }

    /**
     * Get the signal price or last price from the market if any.
     *
     * @param string $pair
     * @param string $orderType
     * @param $limitPrice
     * @return false|float
     */
    public function getPrice2(string $pair, string $orderType, $limitPrice)
    {
        try {
            $marketEncoder = BaseMarketEncoder::newInstance($this->exchangeName, $this->exchangeAccountType);
            $market = $marketEncoder->withoutSlash($pair);

            if ('market' === strtolower($orderType) || empty($limitPrice)) {
                $this->updateLastPrice($market);
                $priceFromSignal = $this->lastPrice;
            } else {
                $priceFromSignal = $limitPrice;
            }

            return $this->getPriceToPrecision($priceFromSignal, $market);
        } catch (\Exception $e) {
            $this->Monolog->sendEntry('error', "Couldn't get the price for $pair: " . $e->getMessage());

            return false;
        }
    }

    public function getPriceToPrecision($price, $symbol)
    {
        if ($price == 0) {
            $this->Monolog->sendEntry('DEBUG', 'Given price for precision is 0, nothing to do.');
            return false;
        }

        $market = 'Unknown Market';

        try {
            $market = $this->symbolParamToCcxt($symbol);
            // Ensure symbol exists in Exchange.
            $this->exchangeClass->market($market);

            $priceWithPrecision = $this->exchangeClass->priceToPrecision($market, $price);
            if (empty($priceWithPrecision)) {
                $this->Monolog->sendEntry(
                    'ERROR',
                    sprintf(
                        "Price %s retrieved precision is empty = %s at %s market and %s symbol",
                        $price,
                        $priceWithPrecision,
                        $market,
                        $symbol
                    )
                );
            }

            return $priceWithPrecision;
        } catch (\Exception $e) {
            $this->Monolog->sendEntry(
                'warning',
                "Couldn't get the price ($price) precision for $symbol $market: " . $e->getMessage()
            );
        }

        return false;
    }

    private function loadMarkets()
    {
        return BaseMarketEncoder::newInstance($this->exchangeName, $this->exchangeAccountType)->loadMarkets();
//        $cache = $this->arrayCache->getItem($this->exchangeName);
//
//        if ($cache->isHit()) {
//            return $cache->get();
//        }
//
//        $markets = $this->TradeApiClient->getExchangeMarketData($this->exchangeName);
//
//        $cache->set($markets);
//        $this->arrayCache->save($cache);
//
//        return $markets;
    }

    /**
     * Given an user ID, reconnect to the exchange with their credentials.
     *
     * @param $userId
     * @param string $internalExchangeId
     * @param bool $isInternalExchangeId
     * @param bool $forceKeysCheck
     * @return bool
     */
    public function reConnectExchangeWithKeys($userId, string $internalExchangeId, $isInternalExchangeId = false, $forceKeysCheck = false)
    {
        //$this->Monolog->sendEntry('debug', "Looking for user");
        $user = $this->newUser->getUser($userId);
        if (!$user) {
            return false;
        }

        //$this->Monolog->sendEntry('info', "Internal Exchange Id: $internalExchangeId");

        foreach ($user->exchanges as $exchangeOption) {
            if ($exchangeOption->internalId == $internalExchangeId) {
                $exchange = $exchangeOption;
            }
        }

        if (empty($exchange)) {
            $this->Monolog->sendEntry('info', "Internal Exchange Id: $internalExchangeId not found in user exchanges");
            return false;
        }

        $this->exchangeIsPaperTrading = isset($exchange->paperTrading) ? $exchange->paperTrading : false; //Todo: Temporal solution
        return $this->useExchangeConnectionKeys($userId, $exchange, $forceKeysCheck);
    }

    public function exchangeSetNewKeys($apiKey, $secret, $password, $isPaperExchange, $userId, $internalExchange = false)
    {
        // if authenticated and has same key and is in same mode (paper/real) with current exchange
        if ($this->exchangeAuth && $this->checkAuth($apiKey) && ($isPaperExchange == ($this->exchangeClass instanceof PaperTradeExchange))) {
            return true;
        }

        try {
            // "pop" exchange if paper trading exchange
            if ($this->exchangeClass instanceof PaperTradeExchange) {
                $this->exchangeClass = $this->exchangeClass->getExchange();
            }

            // if paper exchange set user id to restrict queries to its positions
            if ($isPaperExchange) {
                global $mongoDBLink;
                $paperTradeOrderManager = PaperTradingEngineFactory::newInstance(
                    $this->exchangeClass,
                    $mongoDBLink,
                    $this->HistoryDB,
                    $this->lastTradesProvider,
                    $this->lastPriceService,
                    $userId
                );

                $this->exchangeClass = new PaperTradeExchange($this->exchangeClass, $paperTradeOrderManager);
            }

            // maybe we have to saved cached data for user an restore later
            //$this->Monolog->sendEntry('debug', 'Resetting cache');
            $this->exchangeClass->resetCachedData();
            //$this->Monolog->sendEntry('debug', 'Setting auth');
            $this->exchangeClass->setAuth($apiKey, $secret, $password);
            //$this->Monolog->sendEntry('debug', 'Fetching balance');
            $this->exchangeClass->fetchBalance();
            //$this->Monolog->sendEntry('debug', 'Checking auth');
            $this->exchangeAuth = $this->checkAuth($apiKey);
            //$this->Monolog->sendEntry('debug', 'Everything done.');
            // We sleep two seconds to be sure that markets are loaded.
            // I'm removing this sleep because it's adding too much latency, if we start getting errors, will have to add it again.
            /*if (!$isPaperExchange) {
                sleep(2);
            }*/
        } catch (\Exception $e) {
            $this->exchangeAuth = false;
            if ($this->Monolog) {
                $this->Monolog->sendEntry(
                    'warning',
                    sprintf(
                        'Authentication failed for user ID "%s" with error:\n %s \n %s',
                        $userId,
                        $e->getMessage(),
                        $e->__toString()
                    )
                );
            }
            /*if ($internalExchange && !empty($internalExchange->internalId)) {
                $this->Monolog->sendEntry('debug', 'Increasing user exchange disconnection counter.');
                $currentCounter = empty($internalExchange->checkAuthCount) ? 0 : $internalExchange->checkAuthCount;
                $currentCounter++;
                $this->newUser->updateKeysStatusForExchange($userId, $internalExchange->internalId, null, $currentCounter);
            }*/
        }

        return $this->exchangeAuth;
    }

    public function parseMarket($market)
    {
        $substitutions = $this->unifyingMarkets();
        $market = str_replace('BCHABC', 'BCH', $market);
        foreach ($substitutions as $substitution) {
            if (strpos($market, 'YOYOW') === false && strpos($market, 'WAXP') === false) {
                if (strpos($market, $substitution['original']) !== false) {
                    if ($this->startsWith($market, $substitution['original']) || $this->endsWith($market, $substitution['original'])) {
                        return str_replace($substitution['original'], $substitution['substitution'], $market);
                    }
                }
            }
        }

        return $market;
    }

    private function startsWith($string, $startString)
    {
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
    }

    private function endsWith($string, $endString)
    {
        $len = strlen($endString);
        if ($len == 0) {
            return true;
        }
        return (substr($string, -$len) === $endString);
    }

    public function sendOrder(
        $userId,
        $exchangeId,
        $symbol,
        $orderType,
        $orderSide,
        $amount,
        $price,
        $options,
        $isInternalExchangeId = false,
        $positionId = false,
        $leverage = false
    ) {
        try {
            $this->reConnectExchangeWithKeys($userId, $exchangeId, $isInternalExchangeId);
            if (!$this->exchangeAuth) {
                $this->Monolog->sendEntry('warning', "Couldn't connect from data on user $userId");
                return [
                    'error' => 'Invalid API key/secret pair',
                ];
            }
            // prepare exchange params
            $params = PositionUtils::generateExchangeExtraParamsFromOptions($options);
            $symbol = $this->symbolParamToCcxt($symbol);

            if (!is_array($options)) {
                $options = [];
            }

            $this->Monolog->sendEntry('debug', "Creating $symbol $orderSide, $orderType order with amount $amount at price $price", $options);

            $marginMode = $options['marginMode'] ?? 'isolated';
            if ('ignore' !== $marginMode && $this->exchangeClass->supportsMarginMode()) {
                if ('cross' === $marginMode) {
                    $leverage = $this->exchangeClass->getLeverageForCrossMargin();
                }
                $data = $this->exchangeClass->setMarginMode($symbol, $marginMode);
                $symbolData = $this->exchangeClass->findSymbolFormatAgnostic($symbol);
                $this->Monolog->sendEntry(
                    'debug',
                    "Updating marginMode to $marginMode " . new JsonResponse($data),
                    $symbolData
                );
            }

            if (!empty($leverage) && in_array($this->exchangeAccountType, ['futures', 'margin'])) {
                $symbolData = $this->exchangeClass->findSymbolFormatAgnostic($symbol);
                if (is_array($symbolData)) {
                    $data = $this->exchangeClass->changeLeverage($symbolData['id'], $leverage);
                    //$this->Monolog->sendEntry('debug', "Updating leverage to $leverage " . new JsonResponse($data), $symbolData);
                } else {
                    //$this->Monolog->sendEntry('debug', "Updating leverage failed to get symbol $symbol");
                    return [
                        'error' => 'Symbol not found for updating leverage'
                    ];
                }
            }

            //Todo: Probably this is a good point for including the isolate/cross contract mode.

            $order = $this->exchangeCreateOrder($symbol, $orderType, $orderSide, $amount, $price, $params, $positionId);

            // check client order id
            if ((null != $order->getZignalyClientId()) && ($order->getZignalyClientId() != $order->getRecvClientId())) {
                $this->Monolog->sendEntry(
                    'debug',
                    'Client order id does not match: '
                    . $order->getZignalyClientId() . ' ' . $order->getRecvClientId()
                );
            }

            if (!is_object($order) && isset($order['error'])) {
                return $order;
            } elseif (empty($order->getId())) {
                //Todo: Look for the order in the exchange.
                return ['error' => 'Order ID not returned'];
            } elseif ($order->getAmount() == null || $order->getStatus() == null) {
                $marketEncoder = BaseMarketEncoder::newInstance($this->exchangeName, $this->exchangeAccountType);
                $symbolWithoutSlash = $marketEncoder->fromCcxt($order->getSymbol());
                $orderFromDb = $this->Order->getOrder($this->exchangeName, $this->exchangeAccountType, $order->getId(), $symbolWithoutSlash);
                if (!empty($orderFromDb['status'])) {
                    $this->Monolog->sendEntry('debug', "Found order in DB for {$order->getSymbol()}");
                    return new ExchangeOrderCcxt($orderFromDb);
                }
                return $this->exchangeOrderStatus($order->getId(), $symbol);
            }

            return $order;
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * get exchange open orders
     */
    public function getOpenOrders(
        $userId,
        $exchangeId,
        $symbol,
        $isInternalExchangeId = false,
        $since = null,
        $limit = null
    ) {
        try {
            $this->reConnectExchangeWithKeys($userId, $exchangeId, $isInternalExchangeId);
            if (!$this->exchangeAuth) {
                $this->Monolog->sendEntry('warning', "Couldn't connect from data on user $userId");
                return [
                    'error' => 'Invalid API key/secret pair',
                ];
            }
            $symbol = $this->symbolParamToCcxt($symbol);

            $this->Monolog->sendEntry('debug', "Getting open $symbol orders for user $userId");

            $orders = $this->exchangeClass->getOpenOrders($symbol, $since, $limit);

            return $orders;
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * get exchange closed orders
     */
    public function getClosedOrders(
        $userId,
        $exchangeId,
        $symbol,
        $isInternalExchangeId = false,
        $since = null,
        $limit = null
    )
    {
        try {
            $this->reConnectExchangeWithKeys($userId, $exchangeId, $isInternalExchangeId);
            if (!$this->exchangeAuth) {
                $this->Monolog->sendEntry('warning', "Couldn't connect from data on user $userId");
                return [
                    'error' => 'Invalid API key/secret pair',
                ];
            }
            $symbol = $this->symbolParamToCcxt($symbol);

            $this->Monolog->sendEntry('debug', "Getting closed $symbol orders for user $userId");

            $orders = $this->exchangeClass->getClosedOrders($symbol, $since, $limit);

            return $orders;
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * get exchange closed orders
     */
    public function getOrders(
        $userId,
        $exchangeId,
        $symbol,
        $isInternalExchangeId = false,
        $since = null,
        $limit = null
    )
    {
        try {
            $this->reConnectExchangeWithKeys($userId, $exchangeId, $isInternalExchangeId);
            if (!$this->exchangeAuth) {
                $this->Monolog->sendEntry('warning', "Couldn't connect from data on user $userId");
                return [
                    'error' => 'Invalid API key/secret pair',
                ];
            }
            $symbol = $this->symbolParamToCcxt($symbol);

            $this->Monolog->sendEntry('debug', "Getting $symbol orders for user $userId");

            $orders = $this->exchangeClass->getOrders($symbol, $since, $limit);

            return $orders;
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * Get the active contracts for the given user/exchange.
     *
     * @param \MongoDB\BSON\ObjectId $userId
     * @param string $exchangeId
     * @return array|\Zignaly\exchange\ExchangePosition[]
     */
    public function getContracts(
        \MongoDB\BSON\ObjectId $userId,
        string $exchangeId
    ) {
        try {
            $this->reConnectExchangeWithKeys($userId, $exchangeId, true);
            if (!$this->exchangeAuth) {
                $this->Monolog->sendEntry('warning', "Couldn't connect from data on user $userId");
                return [
                    'error' => 'Invalid API key/secret pair',
                ];
            }

            return $this->exchangeClass->getPosition();
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * Prepare CCXT options from global configuration to specific exchange class.
     *
     * @param string $exchangeClassId Exchange concrete class ID.
     *
     * @return array
     */
    private function prepareExchangeOptions(string $exchangeClassId): array
    {
        $options = [];
        if (isset($this->ccxtConfig['common'])) {
            $options = array_merge($options, $this->ccxtConfig['common']);
        }

        $exchangeConfigId = strtolower($exchangeClassId);
        if (isset($this->ccxtConfig['exchanges']) && isset($this->ccxtConfig['exchanges'][$exchangeConfigId])) {
            $options = array_merge($options, $this->ccxtConfig['exchanges'][$exchangeConfigId]);
        }

        return $options;
    }

    /**
     * Use appropriate exchange instance for a given user exchange connection.
     *
     * @param string $userId User ID.
     * @param \MongoDB\Model\BSONDocument $exchangeConnection User exchange connection.
     * @param string|null $exchangeType Force to use explicit exchange type for exchange class resolution.
     *
     * @return bool|\Zignaly\exchange\BaseExchange
     */
    public function useConcreteExchangeForConnection(string $userId, BSONDocument $exchangeConnection, $exchangeType = null)
    {
        $this->resetProperties();
        // Backward compatibility for old exchange connections that don't have exchangeName property.
        $exchangeName = isset($exchangeConnection->exchangeName) ? $exchangeConnection->exchangeName : $exchangeConnection->name;
        $exchangeClassId = ExchangeFactory::exchangeNameResolution($exchangeName, $exchangeType);
        $options = $this->prepareExchangeOptions($exchangeClassId);

        if ($exchangeType) {
            // Use explicit type instead of resolve automatically from exchange connection.
            $exchange = ExchangeFactory::createFromNameAndType($exchangeName, $exchangeType, $options);
        } else {
            $exchange = ExchangeFactory::createFromUserExchangeConnection($exchangeConnection, $options);
        }

        if (!$exchange) {
            $this->Monolog->sendEntry('error', "Exchange connection concrete class cannot be found.");
            return false;
        }

        $this->exchangeName = $exchangeName;
        $this->exchangeClass = $exchange;

        $isTestNet = false;
        if (isset($exchangeConnection->isTestnet)) {
            $isTestNet = $exchangeConnection->isTestnet;
        }

        try {
            $this->exchangeClass->loadMarkets(); //Todo: we need to look for a different check.
            if ($isTestNet) {
                $this->exchangeClass->useTestEndpoint();
            }

            $this->useExchangeConnectionKeys($userId, $exchangeConnection);
        } catch (\Exception $e) {
            $this->Monolog->sendEntry('error', "Couldn't connect to the exchange {$this->exchangeName}: "
                . $e->getMessage());

            return false;
        }

        return $this->exchangeClass;
    }

    /**
     * Set used exchange class to a concrete CCXT wrapper instance.
     *
     * @param string $exchangeName Exchange brand name to use.
     * @param string $accountType Exchange type.
     * @param bool $isTest Flag to indicate if TestNet should be used.
     *
     * @return bool
     */
    public function setCurrentExchange(string $exchangeName, $accountType = 'spot', $isTest = false)
    {
        $this->resetProperties();
        $exchangeClassId = ExchangeFactory::exchangeNameResolution($exchangeName, $accountType);
        $instanceCacheId = md5("exchange_{$exchangeClassId}_{$accountType}_{$isTest}");
        $this->exchangeName = $exchangeClassId;
        $this->exchangeAccountType = $accountType;

        try {
            if (!isset($this->createdExchangesClass[$instanceCacheId])) {
                $options = $this->prepareExchangeOptions($exchangeClassId);
                $this->createdExchangesClass[$instanceCacheId] = ExchangeFactory::newInstance($exchangeClassId, new ExchangeOptions($options));
            }

            $this->exchangeClass = $this->createdExchangesClass[$instanceCacheId];
            $this->exchangeClass->loadMarkets();
            if ($isTest) {
                $this->exchangeClass->useTestEndpoint();
            }

            return true;
        } catch (\Exception $e) {
            $this->Monolog->sendEntry('error', "Couldn't connect to the exchange {$this->exchangeName}: "
                . $e->getMessage());

            return false;
        }
    }

    private function checkAuth($apiKey)
    {
        $currentApiKey = $this->exchangeClass->getCurrentAuth();

        return ($currentApiKey === $apiKey);
    }

    private function unifyingMarkets()
    {
        if (!isset($this->substitutions[$this->exchangeName])) {

            //$markets = $this->exchangeClass->loadMarkets();
            $markets = $this->loadMarkets();
            $substitutions = [];

            foreach ($markets as $market) {
                if ($market['base'] != $market['baseId']
                    && !$this->unifyingMarketsCheckDuplicate($substitutions, $market['baseId'], $market['base'])) {
                    $substitutions[] = [
                        'original' => $market['baseId'],
                        'substitution' => $market['base'],
                    ];
                }
                if ($market['quote'] != $market['quoteId']
                    && !$this->unifyingMarketsCheckDuplicate($substitutions, $market['quoteId'], $market['quote'])) {
                    $substitutions[] = [
                        'original' => $market['quoteId'],
                        'substitution' => $market['quote'],
                    ];
                }
            }

            $this->substitutions[$this->exchangeName] = $substitutions;
        }

        return $this->substitutions[$this->exchangeName];
    }

    private function unifyingMarketsCheckDuplicate($substitutions, $original, $substitution)
    {
        foreach ($substitutions as $subs) {
            if (isset($subs['original']) && $subs['original'] == $original && isset($subs['substitution']) && $subs['substitution'] == $substitution) {
                return true;
            }
        }

        return false;
    }

    private function updateLastPrice($market, $force = false)
    {
        if ($force || !$this->lastPrice) {
            try {
                $this->lastPrice = $this->lastPriceService->lastPriceStrForSymbol($this->exchangeClass->getId(), $market);
            } catch (\Exception $e) {
                if ($this->Monolog) {
                    $this->Monolog->sendEntry('error', "Can't get last price from Redis." . $e->getMessage());
                }
            }

            if (!$this->lastPrice) {
                $this->updateLastPriceFromDB($market);
            }

            if ((!$this->lastPrice || $this->lastPrice == null || $this->lastPrice == '') && $this->Monolog) {
                $this->Monolog->sendEntry('error', "Couldn't find last price for $market.");
            }
        }
    }

    private function updateLastPriceFromDB($market)
    {
        try {
            //$lastPrice = $this->HistoryDB->getLastPrice($this->exchangeName . 'Trade', $market);
            $this->Monolog->sendEntry('debug', "getLastPrice {$this->exchangeName} {$market}");
            $lastPrice = $this->lastTradesProvider->getLastPrice($this->exchangeName, $market);

            if (is_numeric($lastPrice) && $lastPrice > 0) {
                $this->lastPrice = $lastPrice;
            }
        } catch (\Exception $e) {
            $this->Monolog->sendEntry('error', "Can't get last price from DB: " . $e->getMessage());
        }
    }

    /**
     * Update the information about a symbol and return true if success or false if it didn't find it.
     *
     * @param string $market
     * @return bool
     */
    private function updateSymbolInfo(string $market)
    {
        $marketEncoder = BaseMarketEncoder::newInstance($this->exchangeName, $this->exchangeAccountType);
        if ($this->symbolInfo && isset($this->symbolInfo['symbol']) &&
            ($marketEncoder->withoutSlash($this->symbolInfo['symbol']) == $market
                || $marketEncoder->withoutSlash($this->symbolInfo['id']) == $market)) {
            return true;
        }

        $try = 0;
        $parsedMarket = $market;
        while ($try < 5) {
            $marketData = $this->marketDataService->getMarket($this->exchangeName, $parsedMarket);
            if ($marketData) {
                $this->symbolInfo = $marketData->asArray();
                return true;
            }

            $this->Monolog->sendEntry('warning', "Couldn't get symbol info for $market ({$parsedMarket}) in exchange {$this->exchangeName}.");
            $try++;
            sleep($try);
        }

        $this->symbolInfo = false;
        $this->Monolog->sendEntry('warning', "Couldn't get symbol $market ({$parsedMarket}) info after 5 attempts.");

        return false;
    }

    /**
     * Convert exception object into error array.
     *
     * @param \Exception $ex
     *
     * @return array
     */
    private function composeErrorFromException(Exception $ex): array
    {
        $error = [
            'error' => $ex->getMessage(),
            'extended' => $ex,
            'trace' => $ex->getTraceAsString()
        ];

        $this->Monolog->sendEntry(
            $this->getLogMethodFromError($error['error']),
            sprintf('Exchange request error: %s', $error['error'])
        );

        return $error;
    }

    private function resetProperties(): void
    {
        global $ccxtExchangesGlobalConfig;

        $this->exchangeClass = null;
        $this->exchangeAuth = false;
        $this->lastPrice = false;
        $this->ccxtConfig = $ccxtExchangesGlobalConfig;
        $this->arrayCache->clear();
    }

    /**
     * Use API keys from user exchange connection.
     *
     * @param string $userId User ID.
     * @param \MongoDB\Model\BSONDocument $exchangeConnection User exchange connection.
     * @param bool $forceKeysCheck
     *
     * @return bool
     */
    private function useExchangeConnectionKeys(string $userId, BSONDocument $exchangeConnection, $forceKeysCheck = false): bool
    {
        $isPaperExchange = !empty($exchangeConnection->paperTrading);
        if (!$isPaperExchange) {
            $exchangeName = isset($exchangeConnection->name) ? strtolower($exchangeConnection->name) : 'none';
            if (!$forceKeysCheck && 'zignaly' !== $exchangeName) {
                if (empty($exchangeConnection->areKeysValid)) {
                    return false;
                }
            }

            if (empty($exchangeConnection->key) || empty($exchangeConnection->secret)) {
                return false;
            }

            //$this->Monolog->sendEntry('debug', 'Decrypting key');
            $apiKey = $this->Security->decrypt($exchangeConnection->key);
            //$this->Monolog->sendEntry('debug', 'Decrypting secret');
            $secret = $this->Security->decrypt($exchangeConnection->secret);
            //$this->Monolog->sendEntry('debug', 'Decrypting password');
            $password = empty($exchangeConnection->password) ? "" : $this->Security->decrypt($exchangeConnection->password);
            //$this->Monolog->sendEntry('debug', 'Decrypting done.');

            if (($secret == "" || $apiKey == "")) {
                $this->Monolog->sendEntry(
                    'warning',
                    "No key/secret for exchange connection {$exchangeConnection->_id->__toString()}, key: $apiKey ($exchangeConnection->key)"
                );

                return false;
            }
        } else {
            $apiKey = '';
            $secret = '';
            $password = '';
        }

        return $this->exchangeSetNewKeys($apiKey, $secret, $password, $isPaperExchange, $userId, $exchangeConnection);
    }

    /**
     * userIncome
     *
     * @param string $symbol Asset symbol to transfer balance for.
     * @param string $incomeType see ExchangeIncomeType
     *
     * @return ExchangeIncome[]
     */
    public function userIncome(string $symbol = null, string $incomeType = null, int $from = null, int $to = null, int $limit = null): array
    {
        try {
            return $this->exchangeClass->income($symbol, $incomeType, $from, $to, $limit);
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * userForceOrders
     *
     * @param string $symbol Asset symbol to transfer balance for.
     * @param string $autoCloseType "LIQUIDATION" for liquidation orders, "ADL" for ADL orders.
     * @param int $from
     * @param int $to
     * @param int $limit
     *
     * @return ExchangeOrder[]
     */
    public function userForceOrders(string $symbol = null, string $autoCloseType = null, int $from = null, int $to = null, int $limit = null): array
    {
        try {
            return $this->exchangeClass->forceOrders($symbol, $autoCloseType, $from, $to, $limit);
        } catch (\Exception $ex) {
            return $this->composeErrorFromException($ex);
        }
    }

    /**
     * convert zignaly symbol to ccxt symbol
     *
     * @param string $market
     * @return void
     */
    public function convertToCcxt($market)
    {
        $marketEncoder = BaseMarketEncoder::newInstance($this->exchangeName, $this->exchangeAccountType);
        return $marketEncoder->toCcxt($market);
    }

    /**
     * convert zignaly symbol id to ccxt, else try to convert removing slash, else return same symbol
     * To be deleted when always send zignaly symbol id (not "base"."quote" or "base/quote")
     */
    private function symbolParamToCcxt($symbol, $base = null, $quote = null)
    {
        try {
            // convert Zignaly pair id to ccxt symbol
            return $this->convertToCcxt($symbol);
        } catch (\Exception $ex) {
            // suppose this $symbol is old base.quote format
            $arr = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
            $method = 'unkown';
            if (count($arr) > 1) {
                $method = $arr[1]['file'] . ' ' . $arr[1]['line'] . ' ' . $arr[1]['function'];
            }
            $this->Monolog->sendEntry('debug', "(SYMBOLWARN)ExchangeCalls->sendOrder not receiving ZIGNALY pair $symbol at $method using {$this->exchangeName} {$this->exchangeAccountType}");
            try {
                $marketEncoder = BaseMarketEncoder::newInstance($this->exchangeName, $this->exchangeAccountType);
                $symbol = $marketEncoder->toCcxt($marketEncoder->withoutSlash($symbol));
            } catch (\Exception $ex) {
                $this->Monolog->sendEntry('debug', "(SYMBOLWARN) expected symbol $symbol baseId.quoteId at $method using {$marketEncoder->getExchangeName()}");
                if (null != $base && null != $quote) {
                    return $this->parseMarket($base . '/' . $quote);
                }
                try {
                    $symbol = $this->exchangeClass->getSymbol4Id($symbol);
                } catch (\Exception $ex) {
                    $this->Monolog->sendEntry('debug', "Error trying to get ccxt symbol from expected id $symbol for {$marketEncoder->getExchangeName()}", $ex);
                }
            }
        }
        return $symbol;
    }

    /**
     * @param int $from
     * @param string|null $asset
     * @return ExchangeFuturesTransfer[]
     */
    public function getFuturesTransfer(int $from, ?string $asset = null): array
    {
        return $this->exchangeClass->getFuturesTransfers($from, $asset);
    }

    /**
     * Get agnostic symbol for exchange
     */
    public function findSymbolFormatAgnostic($symbol) {
        return $this->exchangeClass->findSymbolFormatAgnostic($symbol);
    }
}
