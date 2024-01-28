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


namespace Zignaly\exchange;

use MongoDB\Model\BSONDocument;

interface BaseExchange
{
    const TYPE_FUTURE = 'futures';
    const TYPE_SPOT = 'spot';

    /**
     * Undocumented function
     *
     * @return string
     */
    public function getId();

    public function useTestEndpoint();

    public function setVerbose($verbose);

    public function getVerbose();

    /**
     * Undocumented function
     *
     * @param bool $reload
     *
     * @return array
     */
    public function loadMarkets($reload = true);

    /**
     * get market id for symbol
     *
     * @param string $symbol
     *
     * @return string
     */
    public function getMarketId(string $symbol);

    /**
     * get symbol for market id
     *
     * @param string $id
     *
     * @return string
     */
    public function getSymbol4Id(string $id);
    /**
     * reset exchange cache
     *
     * @return void
     */
    /**
     * Undocumented function
     *
     * @param string $symbol
     *
     * @return object
     */
    public function market(string $symbol);

    /**
     * Find symbol data at exchange markets without worry on symbol format.
     *
     * @param string $symbol Symbol in concatenated or slash separated format.
     *
     * @return array|null Exchange symbol data or null when not found.
     */
    public function findSymbolFormatAgnostic(string $symbol);

    public function resetCachedData();

    /**
     * purge old exchange cache
     *
     * @return void
     */

    public function purgeCachedData(int $beforems = 0);

    /**
     * import previous cache
     *
     * @param array $data
     *
     * @return void
     */
    public function importCachedData(array $data);

    /**
     * export exchange cache
     *
     * @return array
     */
    public function exportCachedData();

    /**
     * set auth info (changeUser)
     *
     * @param string $apiKey
     * @param string $apiSecret
     * @param string $password
     *
     * @return void
     */
    public function setAuth(string $apiKey, string $apiSecret, string $password = "");

    public function getCurrentAuth();

    /**
     * set amount to exchange market precision
     *
     * @param string $market
     * @param float $amount
     *
     * @return float
     */
    public function amountToPrecision(string $market, float $amount);

    /**
     * Round currency amount to the exchange required precision.
     *
     * @param string $currencyCode Currency ID.
     * @param string $network Network ID.
     * @param float $amount Withdraw amount.
     *
     * @return float Amount with adapted currency precision.
     */
    public function withdrawCurrencyNetworkPrecision(string $currencyCode, string $network, float $amount);

    /**
     * set price to exchange market precision
     *
     * @param string $market
     * @param float $price
     *
     * @return float
     */
    public function priceToPrecision(string $market, float $price);

    /**
     * cancel order
     *
     * @param string $orderId
     * @param string $symbol
     *
     * @return ExchangeOrder
     */
    public function cancelOrder(string $orderId, string $symbol = null);

    /**
     * Undocumented function
     *
     * @param ExchangeOrder $order
     *
     * @return ExchangeOrder
     */
    public function orderInfo(string $orderId, string $symbol = null);

    /**
     * create order
     *
     * @param string $symbol
     * @param string $orderType
     * @param string $order
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
    );

    /**
     * Get balance for current user
     *
     * @return ExchangeBalance
     */
    public function fetchBalance();

    /**
     * Get position for associated account
     *
     * @return ExchangePosition[]
     */
    public function getPosition();

    /**
     * Undocumented function
     *
     * @return ExchangeOrder[]
     */
    public function getClosedOrders($symbol = null, $since = null, $limit = null);

    /**
     * Undocumented function
     *
     * @return ExchangeOrder[]
     */
    public function getOpenOrders($symbol = null, $since = null, $limit = null);

    /**
     * Undocumented function
     *
     * @return ExchangeOrder[]
     */
    public function getOrders($symbol = null, $since = null, $limit = null);

    /**
     * Undocumented function
     *
     * @param string $symbol
     * @param int $limit
     *
     * @return void
     */
    public function getOrderbook($symbol, $limit = null);

    /**
     * fetch deposit address for account
     *
     * @param string $code
     * @param string $network
     *
     * @return ExchangeDepositAddress
     */
    public function fetchDepositAddress($code, $network = null);

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
    public function fetchDeposits($code = null, $since = null, $limit = null, $to = null);

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
    public function fetchWithdrawals($code = null, $since = null, $limit = null, $to = null);

    /**
     * withdraw
     *
     * @param string $code
     * @param float $amount
     * @param string $address
     * @param string $tag
     * @param string $network
     *
     * @return ExchangeWithdrawal
     */
    public function withdraw($code, $amount, $address, $tag = null, $network = null);

    /**
     * Undocumented function
     *
     * @return ExchangeUserTransactionInfo
     */
    public function getUserTransactionInfo();

    /**
     * Undocumented function
     *
     * @param string $symbol
     *
     * @return string[]
     */
    public function getLastTicker($symbol = null);

    /**
     * transfer from coins to exchange internal coin to recover small balances
     *
     * @param string[] $assets
     *
     * @return ExchangeDustTransfer
     */
    public function dustTransfer($assets);

    /**
     * Get symbol leverage in the exchange.
     *
     * When leverage is customized will return the latest defined value, or provide default
     * standard symbol leverage bracket otherwise.
     *
     * @param string $symbol Symbol code formatted for the used exchange.
     *
     * @return \stdClass
     */
    public function getLeverage(string $symbol);

    /**
     * Change symbol leverage in the exchange.
     *
     * @param string $symbol Symbol code formatted for the used exchange.
     * @param int $leverage Desired leverage value to set.
     *
     * @return \stdClass
     */
    public function changeLeverage(string $symbol, int $leverage): \stdClass;

    /**
     * Balance transfer between exchange wallets.
     *
     * @param string $symbol Asset symbol to transfer balance for.
     * @param float $amount Amount to transfer.
     * @param int $type Transfer type, 1 (spot to futures) or 2 (futures to spot).
     *
     * @return array
     */
    public function balanceTransfer(string $symbol, float $amount, int $type): array;

    /**
     * Balance transfer history
     *
     * @param string $asset
     * @param integer $from
     * @return array
     */
    public function balanceTransferHistory(string $asset, int $from): array;
    
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
    public function income(string $symbol = null, string $incomeType = null, $from = null, $to = null, $limit = null);
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
    public function forceOrders(string $symbol = null, string $autoCloseType = null, $from = null, $to = null, $limit = null);

    /**
     * @param int $from
     * @param string|null $asset
     * @return ExchangeFuturesTransfer[]
     */
    public function getFuturesTransfers(int $from, ?string $asset = null): array;

    /**
     * @param BSONDocument $position
     * @param float $amount
     * @return float //new Margin
     */
    public function transferMargin(BSONDocument $position, float $amount):float;

    /**
     * Release exchange resource to avoid memory leak
     *
     * @return void
     */
    public function releaseExchangeResources();

    /**
     * @return bool
     */
    public function supportsMarginMode():bool;

    /**
     * @return float
     */
    public function getLeverageForCrossMargin():float;

    /**
     * @param string $symbol
     * @param string $mode
     * @return mixed|void
     */
    public function setMarginMode(string $symbol, string $mode);
}
