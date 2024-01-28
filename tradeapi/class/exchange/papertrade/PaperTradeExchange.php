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


namespace Zignaly\exchange\papertrade;

use MongoDB\Model\BSONDocument;
use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangeOrder;
use Zignaly\exchange\ExchangeBalance;
use Zignaly\exchange\ExchangePosition;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeIncome;
use Zignaly\exchange\ExchangeTrade;
use Zignaly\exchange\ExchangeTradeFee;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;

class PaperTradeExchange implements BaseExchange
{
     /** @var MarketEncoder */
     protected $marketEncoder;
    /** @var BaseExchange */
    private $baseExchange;
    /** @var PaperTradeOrderManager */
    private $orderManager;

    public function __construct(BaseExchange $baseExchange, PaperTradeOrderManager $orderManager)
    {
        $this->baseExchange = $baseExchange;
        $this->orderManager = $orderManager;
        $this->marketEncoder = BaseMarketEncoder::newInstance($this->getId());
    }

    public function getExchange()
    {
        return $this->baseExchange;
    }

    public function getId()
    {
        return $this->baseExchange->getId();
    }

    public function useTestEndpoint()
    {
        $this->baseExchange->useTestEndpoint();
    }

    public function setVerbose($verbose)
    {
        $this->baseExchange->setVerbose($verbose);
    }

    public function getVerbose()
    {
        return $this->baseExchange->getVerbose();
    }

    /**
     * Undocumented function
     *
     * @param bool $reload
     *
     * @return array
     */
    public function loadMarkets($reload = true)
    {
        return $this->baseExchange->loadMarkets();
    }

    /**
     * get market id for symbol
     *
     * @param string $symbol
     *
     * @return string
     */
    public function getMarketId(string $symbol)
    {
        return $this->baseExchange->getMarketId($symbol);
    }

    /**
     * get symbol for market id
     *
     * @param string $id
     *
     * @return string
     */
    public function getSymbol4Id(string $id)
    {
        return $this->baseExchange->getSymbol4Id($id);
    }

    /**
     * @inheritDoc
     */
    public function findSymbolFormatAgnostic(string $symbol)
    {
        if (isset($this->baseExchange->marketsById[$symbol])) {
            return $this->baseExchange->marketsById[$symbol];
        }

        if (isset($this->baseExchange->markets[$symbol])) {
            return $this->baseExchange->markets[$symbol];
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

    /**
     * Undocumented function
     *
     * @param string $symbol
     *
     * @return object
     */
    public function market(string $symbol)
    {
        return $this->baseExchange->market($symbol);
    }

    /**
     * reset exchange cache
     *
     * @return void
     */

    public function resetCachedData()
    {
        $this->baseExchange->resetCachedData();
    }

    /**
     * purge old exchange cache
     *
     * @return void
     */

    public function purgeCachedData(int $beforems = 0)
    {
        $this->baseExchange->purgeCachedData($beforems);
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
        $this->baseExchange->importCachedData($data);
    }

    /**
     * export exchange cache
     *
     * @return array
     */
    public function exportCachedData()
    {
        return $this->baseExchange->exportCachedData();
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
        $this->baseExchange->setAuth($apiKey, $apiSecret, $password);
    }

    public function getCurrentAuth()
    {
        return $this->baseExchange->getCurrentAuth();
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
        return $this->baseExchange->amountToPrecision($market, $amount);
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
        return $this->baseExchange->priceToPrecision($market, $price);
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
        return $this->orderManager->cancelOrder(
            $this->baseExchange->getId(),
            $orderId,
            $symbol
        );
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
        return $this->orderManager->getOrderStatus(
            $this->baseExchange->getId(),
            $orderId,
            $symbol
        );
    }

    /**
     * create order
     *
     * @param string $symbol
     * @param string $orderType
     * @param string $order
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
        return $this->orderManager->createOrder(
            $this->baseExchange->getId(),
            $symbol,
            $orderType,
            $orderSide,
            $amount,
            $price,
            $params,
            $positionId
        );
    }

    /**
     * Get balance for current user
     *
     * @return ExchangeBalance
     */
    public function fetchBalance()
    {
        return $this->orderManager->fetchBalance();
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
        throw new \Exception('Not implemented');
    }

    /**
     * Undocumented function
     *
     * @return ExchangeOrder[]
     */
    public function getOpenOrders($symbol = null, $since = null, $limit = null)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * Undocumented function
     *
     * @return ExchangeOrder[]
     */
    public function getOrders($symbol = null, $since = null, $limit = null)
    {
        return $this->orderManager->getOrders($this->baseExchange->getId(), $symbol);
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
        return $this->baseExchange->getOrderbook($symbol, $limit);
    }

    /**
     * fetch deposit address for account
     *
     * @param string $code
     * @param string $network
     *
     * @return ExchangeDepositAddress
     */
    public function fetchDepositAddress($code, $network = null)
    {
        throw new \Exception('Not implemented');
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
        throw new \Exception('Not implemented');
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
        throw new \Exception('Not implemented');
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
     * @return void
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
     * Calculate fee for order
     *
     * @param ExchangeTrade $order
     *
     * @return ExchangeTradeFee
     */
    public function calculateFeeForTrade(ExchangeTrade $trade)
    {
        return $this->baseExchange->calculateFeeForTrade($trade);
    }

    /**
     * set listener for event
     *
     * @param string $event
     * @param callable $listener
     *
     * @return BaseExchange
     */
    public function on(string $event, callable $listener)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * set listener for only once event
     *
     * @param string $event
     * @param callable $listener
     *
     * @return BaseExchange
     */
    public function once(string $event, callable $listener)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * remove listener from event
     *
     * @param string $event
     * @param callable $listener
     *
     * @return void
     */
    public function removeListener(string $event, callable $listener)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * remove all listener for event
     *
     * @param string $event
     *
     * @return void
     */
    public function removeAllListeners(string $event = null)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * get al listener for an evnt
     *
     * @param string $event
     *
     * @return callable[]
     */
    public function listeners(string $event = null)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * subscribe event symbols
     *
     * array (
     *   array(
     *     'event'=> 'ob',
     *     'symbol'=> 'ETH/BTC',
     *     'params'=> array()
     *   )
     * )
     *
     * events: 'ob', 'trade', 'ticket', 'ohlcv'
     *
     * @param [] $eventSymbols
     *
     * @return void
     */
    public function subscribeEvents($eventSymbols)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * unsubscribe event symbols
     *
     * array (
     *   array(
     *     'event'=> 'ob',
     *     'symbol'=> 'ETH/BTC',
     *     'params'=> array()
     *   )
     * )
     *
     * events: 'ob', 'trade', 'ticker', 'ohlcv'
     *
     * @param [] $eventSymbols
     *
     * @return void
     */
    public function unsubscribeEvents($eventSymbols)
    {
        throw new \Exception('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function getLeverage(string $symbol): \stdClass
    {
        // Default to 20 for paper trading purposes.
        return (object)[
            "$symbol" => [
                'leverage' => 20,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function changeLeverage(string $symbol, int $leverage): \stdClass
    {
        // Simulate the desired leverage update.
        return (object)[
            "$symbol" => [
                'leverage' => $leverage,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function balanceTransfer(string $symbol, float $amount, int $type): array
    {
        // Not supported in paper trading.
        return [];
    }

    /**
     * @inheritDoc
     */
    public function balanceTransferHistory(string $asset, int $from): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function withdrawCurrencyNetworkPrecision(string $currencyCode, string $network, float $amount)
    {
        return $amount;
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
    public function transferMargin(BSONDocument $position, float $amount): float
    {
        return 0.0;
    }
    /**
     * Release exchange resource to avoid memory leak
     *
     * @return void
     */
    public function releaseExchangeResources()
    {
        $this->baseExchange->releaseExchangeResources();
    }

        /**
     * @return bool
     */
    public function supportsMarginMode(): bool
    {
        return $this->baseExchange->supportsMarginMode();
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
