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

use ccxt;
use MongoDB\Model\BSONDocument;
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangePosition;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\exceptions;
use Zignaly\exchange\ExchangeIncome;
use Zignaly\exchange\ExchangeTrade;
use Zignaly\exchange\ExchangeTradeFee;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\marketencoding\MarketEncoder;

use const ccxt\TRUNCATE;

abstract class BaseExchangeCcxt implements BaseExchange
{
    /** @var MarketEncoder */
    protected $marketEncoder;
    protected $exchange;
    /**
     * ccxt exchange id
     *
     * @var string
     */
    private $id;
    /**
     * exchange options (like ccxt options and more?)
     *
     * @var ExchangeOptions
     */
    private $options;

    /**
     * create base exchange instance
     *
     * @param string $id
     * @param ExchangeOptions $options
     */
    public function __construct(string $id, ExchangeOptions $options)
    {
        $this->id = strtolower($id);
        $this->options = $options;
        $cl = __NAMESPACE__.'\\ccxtpatch\\'.$this->id;
        if (!class_exists($cl, true)) {
            $cl = 'ccxt\\'.$this->id;
        }

        $this->exchange = new  $cl ($options->ccxtOptions);
        $this->marketEncoder = BaseMarketEncoder::newInstance($this->getId());
    }

    public function useTestEndpoint()
    {
        if (array_key_exists('test', $this->exchange->urls)) {
            $this->exchange->urls['api'] = $this->exchange->urls['test'];
        } else {
            throw new exceptions\ExchangeTestEndpointNotAvailException ("This exchange not provide test endpoint");
        }
    }

    public function setVerbose($verbose)
    {
        $this->exchange->verbose = $verbose;
    }

    public function getVerbose()
    {
        return $this->exchange->verbose;
    }

    public function releaseExchangeResources()
    {
        try {
            \curl_close($this->exchange->curl);
            $this->exchange->curl = null;
        } catch (\Exception $ex) {
            // echo $ex->getMessage();
        }
    }

    /**
     * Undocumented function
     *
     * @return array
     */
    public function loadMarkets($reload = false)
    {
        try {
            return $this->exchange->load_markets($reload);
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex);
        }

    }

    public function getMarketId(string $symbol)
    {
        // ensure markets are loaded
        $this->loadMarkets();

        return $this->exchange->market_id($symbol);
    }

    public function getSymbol4Id(string $id)
    {
        // ensure markets are loaded
        $this->loadMarkets();

        // get symbol for id
        return $this->exchange->safe_symbol($id);
    }


    /**
     * @inheritDoc
     */
    public function findSymbolFormatAgnostic(string $symbol)
    {
        if (isset($this->exchange->marketsById[$symbol])) {
            return $this->exchange->marketsById[$symbol];
        }

        if (isset($this->exchange->markets[$symbol])) {
            return $this->exchange->markets[$symbol];
        }
        // add this to ensure converting BitMEX symbols to CCXT
        // e.g. if XBTUSD is received the first condition would 
        // convert it to CXCT symbol, but some market ids in BitMEX
        // starts with a dot, but zignaly remove this dot to 
        // handle them internally. The code below can do the
        // conversion (besides this dot-prefix markets used to be 
        // inactive, so maybe this could be useless)
        try {
            $ccxtSymbol = $this->marketEncoder->toCcxt($symbol);

            if (isset($this->exchange->markets[$ccxtSymbol])) {
                return $this->exchange->markets[$ccxtSymbol];
            }

        } catch (\Exception $ex) {
        }

        return null;
    }

    public function market(string $symbol)
    {
        return $this->exchange->market($symbol);
    }

    /**
     * reset exchange cache
     *
     * @return void
     */

    public function resetCachedData()
    {
        $this->exchange->orders = array();
    }

    /**
     * purge old exchange cache
     *
     * @return void
     */

    public function purgeCachedData(int $beforems = 0)
    {
        $before = $this->exchange->milliseconds() - $beforems;
        $this->exchange->purge_cached_orders($before);
    }

    /**
     * import previous cache
     *
     * @param array $data
     *
     * @return void
     */
    public function importCachedData(array $data)
    {
        $this->exchange->orders = array();
        if (isset($data['orders']) && is_array($data['orders'])) {
            $this->exchange->orders = $data['orders'];
        }
    }

    /**
     * export exchange cache
     *
     * @return array
     */
    public function exportCachedData()
    {
        return array("orders" => $this->exchange->orders);
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
        $this->exchange->apiKey = $apiKey;
        $this->exchange->secret = $apiSecret;
        $this->exchange->password = $password;
        $this->exchange->options['hasAlreadyAuthenticatedSuccessfully'] = false;
    }

    public function getCurrentAuth()
    {
        return $this->exchange->apiKey;
    }

    /**
     * set amount to exchange market precision
     *
     * @param string $market
     * @param float $amount
     *
     * @return float
     */
    public function amountToPrecision(string $market, float $amount)
    {
        return $this->exchange->amountToPrecision($market, $amount);
    }

    /**
     * set price to exchange market precision
     *
     * @param string $market
     * @param float $price
     *
     * @return float
     */
    public function priceToPrecision(string $market, float $price)
    {
        return $this->exchange->priceToPrecision($market, $price);
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
            return new ExchangeOrderCcxt ($this->exchange->cancel_order($orderId, $symbol));
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
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
            return new ExchangeOrderCcxt ($this->exchange->fetchOrder($orderId, $symbol));
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
            $order = $this->exchange->create_order($symbol, $type, $side, $amount, $price);

            return new ExchangeOrderCcxt($order);
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * Get balance for current user
     *
     * @return ExchangeBalance
     */
    public function fetchBalance()
    {
        try {
            return new ExchangeBalanceCcxt($this->exchange->fetch_balance());
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * Get position for associated account
     *
     * @return ExchangePosition[]
     */
    public function getPosition()
    {
        throw new \Exception('Not implemented');
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
     * @param string $symbol
     * @param int $limit
     *
     * @return void
     */
    public function getOrderbook($symbol, $limit = null)
    {
        try {
            return $this->exchange->fetchOrderBook($symbol, $limit);
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
        $feeCost = $trade->getCost() * 0.075;

        return new ExchangeTradeFeeCcxt($feeCost, $quote, null);
    }

    /**
     * parse ccxt exception
     *
     * @param \Exception $ccxtException
     * @param string $message
     *
     * @return ExchangeException
     */
    protected function parseCcxtException($ccxtException, $message = "")
    {
        if ($ccxtException instanceof ccxt\AuthenticationError) {
            return new exceptions\ExchangeAuthException($message, 0, $ccxtException);
        } elseif (($ccxtException instanceof ccxt\ArgumentsRequired) ||
            ($ccxtException instanceof ccxt\BadRequest) ||
            ($ccxtException instanceof ccxt\BadResponse)) {
            return new exceptions\ExchangeInvalidFormatException($message, 0, $ccxtException);
        } elseif ($ccxtException instanceof ccxt\InsufficientFunds) {
            return new exceptions\ExchangeInsufficientFundsException ($message, 0, $ccxtException);
        } elseif ($ccxtException instanceof ccxt\InvalidAddress) {
            return new exceptions\ExchangeInvalidAddressException ($message, 0, $ccxtException);
        } elseif ($ccxtException instanceof ccxt\OrderNotFound) {
            return new exceptions\ExchangeOrderNotFoundException ($message, 0, $ccxtException);
        } elseif ($ccxtException instanceof ccxt\InvalidOrder) {
            return new exceptions\ExchangeInvalidOrderException ($message, 0, $ccxtException);
        } elseif ($ccxtException instanceof ccxt\RequestTimeout) {
            return new exceptions\ExchangeTimeoutException ($message, 0, $ccxtException);
        } elseif ($ccxtException instanceof ccxt\NetworkError) {
            return new exceptions\ExchangeNetworkErrorException ($message, 0, $ccxtException);
        }

        return new exceptions\ExchangeUnexpectedException($message, 0, $ccxtException);
    }

    /**
     * fetch deposit address for account
     *
     * @param string $code
     *
     * @return ExchangeDepositAddress
     */
    public function fetchDepositAddress($code, $network = null)
    {
        if ((!array_key_exists("fetchDepositAddress", $this->exchange->has)) &&
            (!$this->exchange->has("fetchDepositAddress"))) {
            throw new \Exception('Not implemented');
        }

        try {
            $params = array();
            if ($network != null) {
                $params["network"] = $network;
            }

            return new ExchangeDepositAddressCcxt($this->exchange->fetch_deposit_address($code, $params));
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * fetch deposit history
     *
     * @param string $code
     * @param int $since
     * @param int $limit
     * @param int $to
     *
     * @return ExchangeTransaction[]
     */
    public function fetchDeposits($code = null, $since = null, $limit = null, $to = null)
    {
        if ((!array_key_exists("fetchDeposits", $this->exchange->has)) &&
            (!$this->exchange->has("fetchDeposits"))) {
            throw new \Exception('Not implemented');
        }

        try {
            $deposits = $this->exchange->fetchDeposits($code, $since, $limit);
            $ret = array();
            foreach ($deposits as $deposit) {
                $ret[] = new ExchangeTransactionCcxt($deposit);
            }

            return $ret;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * fetch withdrawal history
     *
     * @param string $code
     * @param int $since
     * @param int $limit
     * @param int $to
     *
     * @return ExchangeTransaction[]
     */
    public function fetchWithdrawals($code = null, $since = null, $limit = null, $to = null)
    {
        if ((!array_key_exists("fetchWithdrawals", $this->exchange->has)) &&
            (!$this->exchange->has("fetchWithdrawals"))) {
            throw new \Exception('Not implemented');
        }

        try {
            $withdrawals = $this->exchange->fetchWithdrawals($code, $since, $limit);
            $ret = array();
            foreach ($withdrawals as $withdrawal) {
                $ret[] = new ExchangeTransactionCcxt($withdrawal);
            }

            return $ret;
        } catch (ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
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
        throw new \Exception('Not implemented');
    }

    /**
     * Undocumented function
     *
     * @return ExchangeUserTransactionInfo
     */
    public function getUserTransactionInfo()
    {
        throw new \Exception("Not Implemented");
    }

    /**
     * Undocumented function
     *
     * @param string $symbol
     *
     * @return string[]
     */
    public function getLastTicker($symbol = null)
    {
        throw new \Exception("Not Implemented");
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
        throw new \Exception("Not Implemented");
    }

    /**
     * @inheritDoc
     */
    public function getLeverage(string $symbol): \stdClass
    {
        // Not supported by all exchanges, by default return empty data object.
        return new \stdClass();
    }

    /**
     * @inheritDoc
     */
    public function changeLeverage(string $symbol, int $leverage): \stdClass
    {
        return [];
    }

    /**
     * @inheritDoc
     *
     * @throws \Exception When exchange not support this operation.
     */
    public function balanceTransfer(string $symbol, float $amount, int $type): array
    {
        throw new \Exception("Balance transfer is not supported with this exchange.");
    }

    /**
     * @inheritDoc
     */
    public function balanceTransferHistory(string $asset, int $from): array
    {
        throw new \Exception("Balance transfer history is not supported with this exchange.");
    }

    /**
     * @inheritDoc
     *
     * @throws \Exception When exchange not support this operation.
     */
    public function withdrawCurrencyNetworkPrecision(string $currencyCode, string $network, float $amount)
    {
        if (!method_exists($this->exchange, "currency_to_precision")) {
            throw new \Exception("Currency precision is not supported with this exchange.");
        }


        // Needed to load the currencies precision.
        $this->loadMarkets();
        $result = $this->getUserTransactionInfo();
        $coinNetworks = $result->getCoinNetworksForCoin($currencyCode);

        // Find used network.
        $matches = array_filter($coinNetworks, function($item) use ($network) {
            return $item->getNetwork() == $network;
        });

        $usedNetwork = isset($matches[0]) ? $matches[0] : null;
        $integerMultiple = $usedNetwork ? $usedNetwork->getWithdrawIntegerMultiple() : 0;

        // Precision based on rules from:
        // https://www.reddit.com/r/BinanceExchange/comments/995jra/getting_atomic_withdraw_unit_from_api/
        if ($integerMultiple == 1) {
           $precision = 0;
        } elseif ($integerMultiple == 0) {
            $precision = 8;
        } else {
            $cleanIntegerMultiple = rtrim((string) $integerMultiple, "0");
            $decimalsPart = substr(strrchr($cleanIntegerMultiple, "."), 1);
            $precision = strlen($decimalsPart);
        }

        return $this->exchange->decimalToPrecision($amount, TRUNCATE, $precision);
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
        throw new \Exception("income is not supported with this exchange.");
    }
     /**
     * User forced orders
     *
     * @param string $symbol
     * @param string $autoCloseType
     * @param int $from
     * @param int $to
     * @param int $limit
     * @return ExchangeOrder[]
     */
    public function forceOrders(string $symbol = null, string $autoCloseType = null, $from = null, $to = null, $limit = null)
    {
        throw new \Exception("income is not supported with this exchange.");
    }
    /**
     * try to get true ccxt symbol. 
     *
     * @param string $symbol
     * @return void
     */
    protected function ensureCcxtSymbol(string $symbol)
    {
        // supose it is encoded for ccxt
        if (isset($this->exchange->markets[$symbol])) {
            return $this->exchange->markets[$symbol];
        }
        // supose it is a zignaly encoded symbol
        try {
            $ccxtSymbol = $this->marketEncoder->toCcxt($symbol);

            if (isset($this->exchange->markets[$ccxtSymbol])) {
                return $this->exchange->markets[$ccxtSymbol];
            }
            
        } catch (\Exception $ex) {
        }
        // else returns original symbol
        return $symbol;
    }

    

    /**
     * @param int $from
     * @param string|null $asset
     * @return array
     */
    public function getFuturesTransfers(int $from, ?string $asset = null): array
    {
        return [];
    }

    /**
     * @param BSONDocument $position
     * @param float $amount
     * @return float
     */
    public function transferMargin(BSONDocument $position, float $amount):float
    {
        return 0.0;
    }

    /**
     * @return bool
     */
    public function supportsMarginMode(): bool
    {
        return false;
    }

    /**
     * @return float
     */
    public function getLeverageForCrossMargin(): float
    {
        return 1.0;
    }

    /**
     * @param string $symbol
     * @param string $mode
     * @return mixed|void
     */
    public function setMarginMode(string $symbol, string $mode)
    {
        //Do nothing
    }
}
