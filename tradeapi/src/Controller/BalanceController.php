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


namespace Zignaly\Controller;

use Behatch\Json\Json;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;
use newPositionCCXT;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Zignaly\Balance\DailyBalance;
use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangeFactory;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Process\DIContainer;
use Zignaly\redis\ZignalyLastPriceRedisService;
use Zignaly\Balance\BalanceService;
use Zignaly\Mediator\PositionCacheMediator;
use Zignaly\redis\ZignalyMarketDataRedisService;

/**
 * Class BalanceController
 * @package Zignaly\Controller
 */
class BalanceController
{
    /**
     * Accounting service
     *
     * @var \Accounting
     */
    private $Accounting;

    /**
     * Process memory cache.
     *
     * @var ArrayAdapter
     */
    private $arrayCache;

    /**
     * @var $currentExchange
     */
    private $currentExchange;
    /**
     * DailyBalance model.
     *
     * @var DailyBalance
     */
    private $dailyBalanceModel;

    /**
     * @var BaseExchange
     */
    private $exchange;

    /** @var $internalExchangeId */
    private $internalExchangeId;

    /**
     * Market data service.
     *
     * @var ZignalyMarketDataRedisService
     */
    private $marketData;

    /**
     * Monolog service.
     *
     * @var \Monolog
     */
    private $monolog;

    /**
     * newPositionCCXT model
     *
     * @var \newPositionCCXT
     */
    private $newPositionCCXT;

    /**
     * PositionCacheGenerator service
     *
     * @var \PositionCacheGenerator
     */
    private $PositionCacheGenerator;

    /**
     * User model.
     *
     * @var \UserFE
     */
    private $userModel;

    /**
     * @var ZignalyLastPriceRedisService
     */
    private $ZignalyLastPriceService;

    /**
     * @var BalanceService
     */
    private $balanceService;

    /**
     * @var \ProfitSharingBalance
     */
    private $profitSharingBalance;

    /**
     * @var \Exchange
     */
    private $exchangeModel;

    /**
     * Provider model.
     *
     * @var \ProviderFE
     */
    private $providerModel;

    /**
     * newUser model.
     *
     * @var \newUser
     */
    private $newUserModel;


    /**
     * BalanceController constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        $container = DIContainer::getContainer();
        $this->Accounting = $container->get('accounting');
        $this->dailyBalanceModel = $container->get('dailyBalance.model');
        if (!$container->has('monolog')) {
            $container->set('monolog', new \Monolog('BalanceController'));
        }
        $this->monolog = $container->get('monolog');
        $this->newPositionCCXT = $container->get('newPositionCCXT.model');
        $this->PositionCacheGenerator = $container->get('PositionCacheGenerator');

        $this->userModel = new \UserFE();
        $this->arrayCache = $container->get('arrayCache');
        $this->arrayCache->clear();
        $this->ZignalyLastPriceService = $container->get('lastPrice');
        $this->balanceService = $container->get('balanceService');
        $this->profitSharingBalance = $container->get('profitSharingBalance.model');
        $this->exchangeModel = $container->get('exchange.model');
        $this->providerModel = new \ProviderFE();
        $this->marketData = $container->get('marketData');
        $this->newUserModel = $container->get('newUser.model');
    }

    /**
     * Return the balance for an user properly formatted.
     *
     * @param array $payload
     */
    public function getHistorical(array $payload): void
    {
        $user = $this->validateCommonConstraints($payload);

        $limit = isset($payload['lastNDays']) && is_numeric($payload['lastNDays']) ? (int)$payload['lastNDays'] : 90;

        $quotes = $this->getQuotesForExchange();

        $balancesPerDay = $this->dailyBalanceModel->getLastNEntriesForUser($user->_id, $limit);
        $returnBalances = [];
        foreach ($balancesPerDay as $balancePerDay) {
            $balance = $this->getBalancePerExchangeInternalId($balancePerDay, $this->internalExchangeId);
            if (null === $balance) {
                continue;
            }
            $returnBalances[] = $this->composeBalance($balance, $balancePerDay->dateKey, $quotes);
        }

        //Calculate cumulative PnL
        $prev = null;
        for (end($returnBalances); null !== key($returnBalances); prev($returnBalances)) {
            $balance = current($returnBalances);
            if (!isset($balance['pnlUSDT'])) {
                break;
            }

            $balance['sumPnlUSDT'] = $balance['pnlUSDT'];

            if ($prev) {
                $balance['sumPnlUSDT'] += $prev['sumPnlUSDT'];
            }
            $prev = $balance;
        }

        $response = [
            'quotes' => $quotes,
            'balances' => $returnBalances,
        ];

        sendHttpResponse($response);
    }

    /**
     * Return a quick summary of exchange numbers
     *
     * @param array $payload
     * @throws InvalidArgumentException
     * @throws \Exception
     */
    public function getQuickExchangeSummary(array $payload): void
    {
        $user = $this->validateCommonConstraints($payload);

        $force = $payload['force'] ?? false;

        $balance = $this->getBalanceForUserExchange($user, $this->internalExchangeId, $force);

        $composedValues = [];
        $onlyTypeFields = false;

        if ($balance) {
            $onlyTypeFields = 'spot' === $balance['exchangeType'];
            [
                $pnlBTC, $pnlUSDT, $totalInvestedBTC, $totalInvestedUSDT, $availableBTC, $availableUSDT,
                $lockedInvestmentBTC, $lockedInvestmentUSDT
            ] = $this->getProfitsAndLossesFromOpenedPositions($user);
            $freeBTC = $this->returnNumOrZero($balance, 'total', 'freeBTC');
            $freeUSDT = $this->returnNumOrZero($balance, 'total', 'freeUSD');

            $composedValues = [
                'freeBTC' => $freeBTC - $availableBTC,
                'freeUSD' => $freeUSDT - $availableUSDT,
                'lockedBTC' => $totalInvestedBTC + $lockedInvestmentBTC,
                'lockedUSD' => $totalInvestedUSDT + $lockedInvestmentUSDT,
                'pnlBTC' => $pnlBTC,
                'pnlUSD' => $pnlUSDT
            ];
        } else {
            $composedValues = [
                'freeBTC' => 0,
                'freeUSD' => 0,
                'lockedBTC' => 0,
                'lockedUSD' => 0,
                'pnlBTC' => 0,
                'pnlUSD' => 0
            ];
        }

        $response = $this->buildResponseFromBalance($balance ?? [], $composedValues, $onlyTypeFields);

        sendHttpResponse($response);
    }


    /**
     * Return the balance for an user properly formatted.
     *
     * @param array $payload
     */
    public function getProfitSharingBalanceHistory(array $payload): void
    {
        $user = $this->validateCommonConstraints($payload);
        if (empty($payload['providerId']) || empty($payload['exchangeInternalId'])) {
            sendHttpResponse(['error' => ['code' => 30]]);
        }
        $providerId = new ObjectId(filter_var($payload['providerId'], FILTER_SANITIZE_STRING));
        $exchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);
        $userId = $user->_id->__toString();

        $balanceHistory = $this->profitSharingBalance->getProfitSharingBalanceHistory(
            $providerId,
            $exchangeInternalId,
            $userId
        );

        sendHttpResponse($balanceHistory);
    }

    /**
     * Return the list of quotes for the exchange from the internal exchange id.
     *
     * @param array $payload
     * @return JsonResponse
     */
    public function getQuoteAssets(array $payload): JsonResponse
    {
        $this->validateCommonConstraints($payload);

        return new JsonResponse($this->getQuotesForExchange());
    }

    /**
     * @param array $payload
     * @return JsonResponse
     */
    public function getBalanceForService(array $payload): JsonResponse
    {
        if (empty($payload['providerId'])) {
            sendHttpResponse(['error' => ['code' => 17]]);
        }

        $token = checkSessionIsActive();

        $user = $this->userModel->getUser($token);

        $providerId = filter_var($payload['providerId'], FILTER_SANITIZE_STRING);
        $provider = $this->providerModel->getProvider($user->_id, $providerId);

        if (isset($provider['error'])) {
            sendHttpResponse($provider);
        }

        if ($provider->userId->__toString() !== $user->_id->__toString()) {
            $this->monolog->sendEntry(
                'debug',
                "User {$user->_id->__toString()} is trying to get the balance from $providerId"
            );
            sendHttpResponse(['error' => ['code' => 17]]);
        }

        /** @var BSONDocument $profitSharingUser */
        $profitSharingUser = $this->userModel->getProfitSharingUser();

        if (null === $profitSharingUser) {
            sendHttpResponse(['error' => ['code' => 43]]);
        }

        $exchangeInternalId = $this->getExchangeInternalIdFromUser($profitSharingUser, $providerId);
        if (null === $exchangeInternalId) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        $balance = $this->getBalanceForUserExchange($profitSharingUser, $exchangeInternalId);

        $response = $this->buildResponseFromBalance($balance, [], true);

        //For spot, change
        if ('spot' === $provider->exchangeType) {
            //Wallet balance is allocated balance
            $this->internalExchangeId = $exchangeInternalId;
            $this->setExchange($profitSharingUser);
            $followers = $this->newUserModel->getUsersFollowingProvider($providerId);
            $providerId = $provider->_id->__toString();
            $usersId = [];
            foreach ($followers as $follower) {
                $providerFollower = $follower->provider->$providerId;
                foreach ($providerFollower->exchangeInternalIds as $exchangeConnected) {
                    $exchangeInternalId = $exchangeConnected->internalId;
                    /*$connectedAt = $this->userModel->getUserConnectedAtProfitSharingService(
                        $follower,
                        $exchangeInternalId,
                        $providerId
                    );*/
                    $usersId[] = $follower->_id->__toString() . ':' . $exchangeInternalId;
                    /*$userBalance = $this->profitSharingBalance->getFirstAllocatedCurrentAllocatedAndProfits(
                        $providerId,
                        $userIdExchangeId,
                        $connectedAt
                    );*/
                }
            }
            $userTotalAllocatedBalance = $this->profitSharingBalance->userTotalAllocatedBalance($provider->_id, $usersId);
            $response['totalWalletUSDT'] = $userTotalAllocatedBalance['total'];;
            $currentPrice = (float)$this->getCurrentPrice('BTCUSDT');
            $response['BTCUSDT'] = $currentPrice;
            $response['totalWalletBTC'] = $currentPrice > 0 ? $allocated / $currentPrice : 0;
        }

        //Get the abstract balance
        $newPositionCCXT = new newPositionCCXT();
        [$usedAbstractPercentage] = $newPositionCCXT->getUsedAbstractPercentage($providerId, $user->_id);

        $response['abstractPercentage'] = (1 - $usedAbstractPercentage) * 100;

        return new JsonResponse($response);
    }

    /**
     * @param BSONDocument $user
     * @param string $internalExchangeId
     * @param bool|null $force
     * @return array|\ArrayAccess|null
     */
    private function getBalanceForUserExchange(BSONDocument $user, string $internalExchangeId, ?bool $force = null)
    {
        $balance = null;

        if (!$force) {
            $balancesPerDay = $this->dailyBalanceModel->getLastNEntriesForUser($user->_id, 1);
            foreach ($balancesPerDay as $balancePerDay) {
                $balance = $this->getBalancePerExchangeInternalId($balancePerDay, $internalExchangeId);
            }
        }

        if (null === $balance) {
            $balance = $this->balanceService->updateBalance($user, $internalExchangeId);
        }

        return $balance;
    }

    /**
     * Convert USDT amount to BTC.
     *
     * @param float $amount
     * @return float|int
     * @throws InvalidArgumentException
     */
    private function convertUSDTAmountToBTC(float $amount)
    {
        if (empty($amount)) {
            return 0.0;
        }

        $convertedAmount = 0.0;

        try {
            $currentPrice = $this->getCurrentPrice('BTCUSDT');
            if (empty($currentPrice)) {
                return 0.0;
            }
            $convertedAmount = $amount / $currentPrice;
        } catch (\Exception $e) {
            $this->monolog->sendEntry('ERROR', "Converting to BTC.{$e->getMessage()}", $amount);
        }

        return $convertedAmount;
    }

    /**
     * Convert amounts from an array to USDT.
     *
     * @param array $amounts
     * @return float|int
     * @throws InvalidArgumentException
     */
    private function convertAmountsToUSDT(array $amounts)
    {
        if (empty($amounts)) {
            return 0.0;
        }

        $convertedAmount = 0.0;

        try {
            foreach ($amounts as $quote => $amount) {
                $symbol = $quote . 'USDT';
                $currentPrice = $this->getCurrentPrice($symbol);
                if (empty($currentPrice)) {
                    continue;
                }

                $conversion = $currentPrice * $amount;

                $convertedAmount += $conversion;
            }
        } catch (\Exception $e) {
            $this->monolog->sendEntry('ERROR', "Converting to USDT.{$e->getMessage()}", $amounts);
        }

        return $convertedAmount;
    }

    /**
     * @param array $position
     * @return bool|int|mixed|string
     * @throws InvalidArgumentException
     */
    private function getCurrentPositionPrice(array $position)
    {
        if (isset($position['pair'])) {
            return $this->getCurrentPrice($position['pair']);
        }

        if (isset($position['exchange']) && strtolower($position['exchange']) === 'bitmex' && isset($position['short'])) {
            return $this->getCurrentPrice($position['short']);
        }

        return $this->getCurrentPrice($position['base'] . $position['quote']);
    }

    /**
     * Get the current price for a given symbol, looking first in the cache.
     *
     * @param string $symbol
     * @return bool|mixed|string
     * @throws InvalidArgumentException
     */
    private function getCurrentPrice(string $symbol)
    {
        $price = 0;
        $cache = $this->arrayCache->getItem($symbol);
        if ($cache->isHit()) {
            $price = $cache->get();
        } else {
            try {
                $price = $this->ZignalyLastPriceService->lastPriceStrForSymbol($this->exchange->getId(), $symbol);

                if (!empty($price)) {
                    $cache->set($price);
                    $this->arrayCache->save($cache);
                }
            } catch (\Exception $e) {
                $this->monolog->sendEntry('ERROR', "Getting last price for $symbol: {$e->getMessage()}");
            }
        }

        return $price;
    }

    /**
     * Return the invested amount and unrealized PnL converted to BTC and USDT from open positions.
     *
     * @param BSONDocument $user
     * @return array
     * @throws InvalidArgumentException
     */
    private function getProfitsAndLossesFromOpenedPositions(BSONDocument $user)
    {
        $pnlUSDT = 0.0;
        $totalInvestedUSDT = 0.0;
        $availableUSDT = 0.0;
        $lockedInvestmentUSDT = 0.0;
        $positions = $this->newPositionCCXT->getOpenPositionsFromUserInternalExchangeId($user->_id, $this->internalExchangeId);
        /*$positions = $this->PositionCacheGenerator->getOpenPositions(
            $user->_id->__toString(),
            $this->internalExchangeId
        );*/
        if (!empty($positions)) {
            $pendingLockedConversions = [];
            $pendingPnLConversions = [];
            $pendingLockedInvestment = [];
            foreach ($positions as $position) {
                // TODO: bitmex
                if (isset($position->exchange->name) && 'bitmex' === strtolower($position->exchange->name)) {
                    continue;
                }
                $openPosition = $this->PositionCacheGenerator->composePositionForCache($position);
                $positionMediator = PositionCacheMediator::fromArray($openPosition);
                $exchangeHandler = $positionMediator->getExchangeHandler();

                $sellingPrice = (float)$this->getCurrentPositionPrice($openPosition);
                list($unrealizedProfitLosses) =
                    $this->Accounting->computeGrossProfitFromCachedOpenPosition($openPosition, $sellingPrice);

                if (!isset($openPosition['availableAmount'])) {
                    $openPosition['availableAmount'] = 0;
                }

                //Correct leverage in case is 0 (cross positions in Bitmex for example)
                $leverage = $openPosition['leverage'] ?? 1;
                $leverage = $leverage > 0 ? $leverage : 1;


                $currentPrice = (float)$this->getCurrentPrice($openPosition['base'] . 'USDT');
                if (0 == $currentPrice) {
                    $currentPrice = (float)$this->getCurrentPrice($openPosition['base'] . 'BTC');
                    // $availableInvestment = $openPosition['availableAmount'] * $currentPrice;
                    $availableInvestment = $exchangeHandler->calculateRealInvestment(
                        $positionMediator->getSymbol(),
                        $openPosition['availableAmount'],
                        $currentPrice
                    );
                    $currentPrice = (float)$this->getCurrentPrice('BTCUSDT');
                    $availableUSDT += $availableInvestment * $currentPrice / $leverage;
                } else {
                    $baseAmount = 'futures' === $openPosition['exchangeType'] ? $openPosition['remainAmount']
                        : $openPosition['availableAmount'];
                    // $availableInvestment = $baseAmount * $currentPrice;
                    $availableInvestment = $exchangeHandler->calculateRealInvestment(
                        $positionMediator->getSymbol(),
                        $baseAmount,
                        $currentPrice
                    );
                    $availableUSDT += $availableInvestment / $leverage;
                }
                if ('USDT' === $openPosition['quote']) {
                    $totalInvestedUSDT += $openPosition['realInvestment'];
                    $pnlUSDT += $unrealizedProfitLosses;
                    $lockedInvestmentUSDT += $openPosition['lockedPendingInvestment'];
                } else {
                    if (empty($pendingLockedConversions[$openPosition['quote']])) {
                        $pendingLockedConversions[$openPosition['quote']] = $openPosition['realInvestment'];
                        $pendingPnLConversions[$openPosition['quote']] = $unrealizedProfitLosses;
                        $pendingLockedInvestment[$openPosition['quote']] = $openPosition['lockedPendingInvestment'];
                    } else {
                        $pendingLockedConversions[$openPosition['quote']] += $openPosition['realInvestment'];
                        $pendingPnLConversions[$openPosition['quote']] += $unrealizedProfitLosses;
                        $pendingLockedInvestment[$openPosition['quote']] += $openPosition['lockedPendingInvestment'];
                    }
                }
            }
        }

        if (!empty($pendingPnLConversions)) {
            $pnlUSDT += $this->convertAmountsToUSDT($pendingPnLConversions);
        }

        if (!empty($pendingLockedConversions)) {
            $totalInvestedUSDT += $this->convertAmountsToUSDT($pendingLockedConversions);
        }

        if (!empty($pendingLockedInvestment)) {
            $lockedInvestmentUSDT += $this->convertAmountsToUSDT($pendingLockedInvestment);
        }

        if (!empty($this->currentExchange->exchangeType) && 'futures' === $this->currentExchange->exchangeType) {
            $availableUSDT = 0;
        }

        $pnlBTC = $this->convertUSDTAmountToBTC($pnlUSDT);
        $totalInvestedBTC = $this->convertUSDTAmountToBTC($totalInvestedUSDT);
        $availableBTC = $this->convertUSDTAmountToBTC($availableUSDT);
        $lockedInvestmentBTC = $this->convertUSDTAmountToBTC($lockedInvestmentUSDT);

        return [
            $pnlBTC,
            $pnlUSDT,
            $totalInvestedBTC,
            $totalInvestedUSDT,
            $availableBTC,
            $availableUSDT,
            $lockedInvestmentBTC,
            $lockedInvestmentUSDT,
        ];
    }

    /**
     * Given a balance, compose the proper return for the frontend.
     *
     * @param array|\ArrayAccess $balance
     * @param string $date
     * @param array $quotes
     * @return array
     */
    private function composeBalance($balance, string $date, array $quotes): array
    {
        $totalBTC = $this->returnNumOrZero($balance, 'total', 'totalBTC');
        $remainingPercentage = 100;
        $returnBalanceEntry = [
            'date' => $date,
            'totalBTC' => $totalBTC,
            'totalFreeBTC' => $this->returnNumOrZero($balance, 'total', 'freeBTC'),
            'totalLockedBTC' => $this->returnNumOrZero($balance, 'total', 'lockedBTC'),
            'totalUSDT' => $this->returnNumOrZero($balance, 'total', 'totalUSD'),
            'totalFreeUSDT' => $this->returnNumOrZero($balance, 'total', 'freeUSD'),
            'totalLockedUSDT' => $this->returnNumOrZero($balance, 'total', 'lockedUSD'),
        ];

        if ('futures' === $balance->exchangeType) {
            $returnBalanceEntry += [
                'totalWalletBTC' => $this->returnNumOrZero($balance, 'total', 'walletBTC'),
                'totalWalletUSDT' => $this->returnNumOrZero($balance, 'total', 'walletUSD'),
                'pnlUSDT' => $this->returnNumOrZero($balance, 'total', 'pnlUSD'),
                'netTransferUSDT' => $this->returnNumOrZero($balance, 'total', 'netTransfer'),
            ];
        }

        foreach ($quotes as $quote) {
            $returnBalanceEntry['free' . $quote] = $this->returnNumOrZero($balance, $quote, 'free');
            $returnBalanceEntry['locked' . $quote] = $this->returnNumOrZero($balance, $quote, 'locked');
            $coinInBTC = $this->returnNumOrZero($balance, $quote, 'totalBTC');
            $coinPercentage = 0.0 === $totalBTC ? 0 : number_format($coinInBTC * 100 / $totalBTC, 2, '.', '');
            $returnBalanceEntry[$quote . 'percentage'] = $coinPercentage;
            $remainingPercentage -= $coinPercentage;
        }
        $returnBalanceEntry['otherPercentage'] = $remainingPercentage;

        return $returnBalanceEntry;
    }

    /**
     * Extract the quotes from the exchange for a given internalExchangeId.
     *
     * @return array
     */
    private function getQuotesForExchange(): array
    {
        $exchangeName = ZignalyExchangeCodes::getRealExchangeName($this->exchange->getId());

        if (!$exchangeName) {
            sendHttpResponse(['error' => ['code' => 85]]);
        }

        $markets = $this->getSymbolsMetadata($exchangeName);
        $quotes = [];
        $ascendexQuotes = ['USDT', 'ETH', 'BTC'];
        foreach ($markets as $market) {
            if (!\in_array($market['quote'], $quotes, false)) {
                if ('ascendex' !== strtolower($exchangeName) || \in_array($market['quote'], $ascendexQuotes, false)) {
                    $quotes[] = $market['quote'];
                }
            }
        }

        if (!\in_array('BNB', $quotes, false) && 'ascendex' !== strtolower($exchangeName)) {
            $quotes[] = 'BNB';
        }

        return $quotes;
    }

    /**
     * Return list of markets for the given exchange.
     *
     * @param string $exchangeName
     * @return array
     */
    private function getSymbolsMetadata(string $exchangeName)
    {
        $symbolsMetadataDigested = [];

        $symbolsMetadata = $this->marketData->getMarkets($exchangeName);

        foreach ($symbolsMetadata as $symbolMetadata) {
            $symbolsMetadataDigested[] = $symbolMetadata->asArray();
        }

        return $symbolsMetadataDigested;
    }

    /**
     * Return the proper value or 0.
     *
     * @param array|\ArrayAccess $data
     * @param string $value
     * @param string $value2
     * @return float
     */
    private function returnNumOrZero($data, string $value, string $value2): float
    {
        $result = 0.0;

        if (
            !empty($data[$value])
            && (\is_array($data[$value]) || $data[$value] instanceof \ArrayAccess)
            && !empty($data[$value][$value2])
        ) {
            $returnValue = is_object($data[$value][$value2]) ? $data[$value][$value2]->__toString() : $data[$value][$value2];
            $result = (float)round($returnValue, 8);
        }

        return $result;
    }

    /**
     * Extract the balance for the requested exchange account.
     *
     * @param object $balancePerDay
     * @param string $internalExchangeId
     * @return \ArrayAccess|null
     */
    private function getBalancePerExchangeInternalId(object $balancePerDay, string $internalExchangeId): ?\ArrayAccess
    {
        $result = null;

        if (!empty($balancePerDay->balances)) {
            foreach ($balancePerDay->balances as $balance) {
                if ($balance->exchangeInternalId === $internalExchangeId) {
                    $result = $balance;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Set the current exchange from the internal exchange id.
     *
     * @param BSONDocument $user
     */
    private function setExchange(BSONDocument $user): void
    {
        if (empty($user->exchanges)) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        foreach ($user->exchanges as $exchange) {
            if ($exchange->internalId === $this->internalExchangeId) {
                $currentExchange = $exchange;
                break;
            }
        }

        if (empty($currentExchange)) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        $exchangeName = !empty($currentExchange->exchangeName) ? $currentExchange->exchangeName
            : $currentExchange->name;
        $exchangeType = !empty($currentExchange->exchangeType) ? $currentExchange->exchangeType : 'spot';
        $this->exchange = ExchangeFactory::createFromNameAndType($exchangeName, $exchangeType, []);
        $this->currentExchange = $currentExchange;
    }

    /**
     * Set the exchange based on id and type.
     * @param string $exchangeId
     * @param string $exchangeType
     */
    private function setExchangeFromIdAndType(string $exchangeId, string $exchangeType)
    {
        $exchange = $this->exchangeModel->getExchange($exchangeId);
        if (empty($exchange->name)) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        $this->exchange = ExchangeFactory::createFromNameAndType($exchange->name, $exchangeType, []);
    }

    /**
     * Validate that request pass expected contraints.
     *
     * @param array $payload Request payload.
     *
     * @return BSONDocument Authenticated user.
     */
    private function validateCommonConstraints(array $payload): BSONDocument
    {
        $token = checkSessionIsActive();

        if (
            empty($payload['exchangeInternalId'])
            && (empty($payload['exchangeId']) || empty($payload['exchangeType']))
        ) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        $user = $this->userModel->getUser($token);

        if (!empty($payload['exchangeId']) && !empty($payload['exchangeType'])) {
            $exchangeId = filter_var($payload['exchangeId'], FILTER_SANITIZE_STRING);
            $exchangeType = filter_var($payload['exchangeType'], FILTER_SANITIZE_STRING);
            $this->setExchangeFromIdAndType($exchangeId, $exchangeType);
        } else {
            $this->internalExchangeId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);
            $this->setExchange($user);
        }

        return $user;
    }

    /**
     * Look for the internal exchange id inside a user
     * @param BSONDocument $user
     * @param string $providerId
     * @return string|null
     */
    private function getExchangeInternalIdFromUser(BSONDocument $user, string $providerId): ?string
    {
        $result = null;

        if (!empty($user->exchanges)) {
            foreach ($user->exchanges as $exchange) {
                if ($exchange->internalName === $providerId) {
                    $result = $exchange->internalId;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @param array|\ArrayAccess $balance
     * @param array $composedValues
     * @param bool|null $onlyTypeFields
     * @return array
     */
    private function buildResponseFromBalance(
        $balance,
        array $composedValues,
        ?bool $onlyTypeFields = null
    ): array {
        $type = $balance['exchangeType'] ?? 'spot';

        $spotFields = ['total', 'free', 'locked', 'pnl'];
        $futureFields = ['wallet', 'currentMargin', 'unrealizedProfit', 'margin'];

        $resultFields = [];

        if (!$onlyTypeFields || 'spot' === $type) {
            $resultFields = $spotFields;
        }

        if (!$onlyTypeFields || 'futures' === $type) {
            $resultFields = array_merge($resultFields, $futureFields);
        }

        $result = [];

        foreach ($resultFields as $field) {
            $fieldName = 'total' === $field ?: 'total' . ucfirst($field);
            $result[$fieldName . 'BTC'] = $composedValues[$field . 'BTC']
                ?? $this->returnNumOrZero($balance, 'total', $field . 'BTC');
            $result[$fieldName . 'USDT'] = $composedValues[$field . 'USD']
                ?? $this->returnNumOrZero($balance, 'total', $field . 'USD');
        }

        foreach ($result as &$entry) {
            $precision = $entry > 1 ? 2 : 8;
            $entry = round($entry, $precision);
        }

        return $result;
    }
}
