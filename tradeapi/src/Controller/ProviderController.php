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

use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;
use Monolog;
use RedisHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\ExchangeMediator\ExchangeMediator;
use Zignaly\Process\DIContainer;
use Zignaly\redis\ZignalyMarketDataRedisService;

class ProviderController
{
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
     * User model.
     *
     * @var \UserFE
     */
    private $userModel;

    /**
     * Binance symbol model.
     *
     * @var \BinanceSymbolFE
     */
    private $binanceSymbolModel;

    /**
     * Monolog logging service.
     *
     * @var \Monolog
     */
    private $monolog;

    /**
     * RedisHandler service
     *
     * @var \RedisHandler
     */
    private $RedisPublicCache;

    /**
     * RedisHandler service
     * @var \RedisHandler
     */
    private $RedisHandlerZignalyQueue;



    public function __construct()
    {
        $container = DIContainer::getContainer();

        $this->providerModel = new \ProviderFE();
        $this->userModel = new \UserFE();
        $this->newUserModel = $container->get('newUser.model');
        $this->RedisPublicCache = $container->get('cache.storage');
        $this->binanceSymbolModel = new \BinanceSymbolFE();

        $processName = 'updateNewExchange';
        if (!$container->has('monolog')) {
            $container->set('monolog', new Monolog($processName));
        }
        $this->monolog = $container->get('monolog');
        $this->RedisHandlerZignalyQueue = $container->get('redis.queue');
    }

    /**
     * Cancel the subscription to a provider for a given user.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function cancelFollowerSubscription(Request $request, $payload)
    {
        list($user, $provider, $follower) = $this->validateCommonConstraintsForFollowersSubscriptionActions($request, $payload);

        if ($follower->_id == $user->_id) {
            sendHttpResponse(['error' => ['code' => 80]]);
        }

        if (!isset($provider->options->unfriendly) || !$provider->options->unfriendly) {
            if (isset($follower->stripe) && isset($follower->stripe->planId) && $follower->stripe->planId == '008') {
                sendHttpResponse(['error' => ['code' => 79]]);
            }
        }

        if (!isset($payload['cancel'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }
        $cancel = filter_var($payload['cancel'], FILTER_SANITIZE_STRING);

        return new JsonResponse($this->userModel->modifySubscription($follower->_id, $provider->_id->__toString(), $cancel));
    }

    /**
     * Modify the remaining duration of the subscription, extending or reducing it.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function modifyFollowerSubscriptionDuration(Request $request, $payload)
    {
        list($user, $provider, $follower) = $this->validateCommonConstraintsForFollowersSubscriptionActions($request, $payload);
        $days = isset($payload['days']) ? filter_var($payload['days'], FILTER_SANITIZE_STRING) : false;
        if (!$days || !is_numeric($days)) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        $providerId = $provider->_id->__toString();
        if (
            isset($follower->provider->$providerId->stripe)
            && isset($follower->provider->$providerId->stripe->cancelDate)
            && is_object($follower->provider->$providerId->stripe->cancelDate)
        ) {
            $baseTime = $follower->provider->$providerId->stripe->cancelDate->__toString();
        } else {
            $baseTime = time() * 1000;
        }
        $daysInMiliSeconds = $days * 86400 * 1000;
        $newDate = new \MongoDB\BSON\UTCDateTime($baseTime + $daysInMiliSeconds);
        $enable = $newDate->__toString() > time() * 1000;

        $settings = [
            "provider.$providerId.stripe.cancelDate" => $newDate,
            "provider.$providerId.stripe.enable" => $enable,
            "provider.$providerId.stripe.cancelAtPeriodEnd" => true,
        ];

        return new JsonResponse($this->userModel->updateUser($follower->_id, $settings));
    }

    /**
     * Get the list of followers for a given provider.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getFollowersList(Request $request, $payload)
    {
        $token = checkSessionIsActive();

        $user = $this->userModel->getUser($token);
        if (isset($user['error'])) {
            sendHttpResponse($user);
        }

        $providerId = isset($payload['providerId']) ? filter_var($payload['providerId'], FILTER_SANITIZE_STRING)
            : false;
        if (!$providerId) {
            sendHttpResponse(['error' => ['code' => 30]]);
        }
        $provider = $this->providerModel->getProvider($user->_id, $providerId);

        if (isset($provider['error'])) {
            sendHttpResponse($provider);
        }

        if ($provider->userId != $user->_id) {
            sendHttpResponse(['error' => ['code' => 58]]);
        }

        $followers = $this->newUserModel->getUsersFollowingProvider($providerId, true);
        $isAdmin = $this->userModel->isConnectedAs($user, $token, 'admin');


        return new JsonResponse($this->parseFollowersForProviderManagingDashboard2($followers, $provider, $isAdmin));
    }

    /**
     * Prepare the returned documents from mongoDB in the returned data that the frontend expects.
     *
     * @param \MongoDB\Driver\Cursor $followers
     * @param string $providerId
     * @return array
     */
    private function parseFollowersForProviderManagingDashboard(
        \MongoDB\Driver\Cursor $followers,
        BSONDocument $providerParent
    ) {
        $providerId = $providerParent->_id->__toString();
        $parsedFollowers = [];
        foreach ($followers as $follower) {
            $provider = $follower->provider->$providerId;
            if (!empty($providerParent->disable)) {
                if (isset($provider->allocatedBalance)) {
                    $allocatedBalance = is_object($provider->allocatedBalance) ? $provider->allocatedBalance->__toString() : $provider->allocatedBalance;
                } else {
                    $allocatedBalance = 0;
                }
                if (isset($provider->profitsFromClosedBalance)) {
                    $profitsFromClosedBalance = is_object($provider->profitsFromClosedBalance) ? $provider->profitsFromClosedBalance->__toString() : $provider->profitsFromClosedBalance;
                } else {
                    $profitsFromClosedBalance = 0;
                }
                $decimals = $allocatedBalance > 1 ? 2 : 8;
                $parsedFollowers[] = [
                    'userId' => $follower->_id->__toString(),
                    'email' => $this->truncateEmail($follower->email),
                    'name' => $follower->firstName,
                    'realExchangeConnected' => $this->newUserModel->checkIfConnectedExchangeIsReal($follower, $providerId),
                    'connected' => empty($provider->disable) && !empty($provider->exchangeInternalId),
                    'active' => $this->checkIfProviderIsActive($provider, $follower, $providerParent),
                    'suspended' => !empty($provider->suspended),
                    'allocatedBalance' =>  0.0 ,
                    'profitsFromClosedBalance' =>  0.0,
                    'code' => isset($provider->customerKey) ? $provider->customerKey : '-',
                    'cancelDate' => $this->extractCancelDate($provider),
                    'lastTransactionId' => $this->extractLastTransactionId($provider),
                ];
            } else {
                foreach ($provider->exchangeInternalIds as $exchangeConnected) {
                    $exchangeInternalId = $exchangeConnected->internalId;
                    $userIdExchangeId = $follower->_id->__toString() . ':' . $exchangeInternalId;
                    $retain = empty($exchangeConnected->retain) ? 0 : $exchangeConnected->retain;
                    $profitsShare = $providerParent->profitsShare;
                    $profitsMode = empty($exchangeConnected->profitsMode) ? 'Unknown' : $exchangeConnected->profitsMode;
                    $parsedFollowers[] = [
                        'userId' => $follower->_id->__toString(),
                        'email' => $this->truncateEmail($follower->email),
                        'name' => $follower->firstName,
                        'realExchangeConnected' => $this->newUserModel->checkIfConnectedExchangeIsReal($follower, $providerId),
                        'connected' => empty($exchangeConnected->disconnected),
                        'active' => $this->checkIfProviderIsActive($provider, $follower, $providerParent),
                        'suspended' => !empty($provider->suspended),
                        'allocatedBalance' => $userBalance['currentAllocated'],
                        'profitsFromClosedBalance' => $userBalance['profitsSinceCopying'],
                        'originalAllocated' => $userBalance['allocatedBalance'],
                        'retain' => $retain,
                        'profitsShare' => $profitsShare,
                        'profitsMode' => $profitsMode,
                        'code' => isset($provider->customerKey) ? $provider->customerKey : '-',
                        'cancelDate' => $this->extractCancelDate($provider),
                        'lastTransactionId' => $this->extractLastTransactionId($provider),
                        'unit' => $providerParent->quote,
                    ];
                }
            }
        }

        return $parsedFollowers;
    }

    /**
     * Prepare the returned documents from mongoDB in the returned data that the frontend expects.
     *
     * @param \MongoDB\Driver\Cursor $followers
     * @param string $providerId
     * @param bool $isAdmin
     * @return array
     */
    private function parseFollowersForProviderManagingDashboard2(
        \MongoDB\Driver\Cursor $followers,
        BSONDocument $providerParent,
        bool $isAdmin
    ) {
        $providerId = $providerParent->_id->__toString();
        $parsedFollowers = [];
        if ( !empty($providerParent->disable)) {
            foreach ($followers as $follower) {
                $provider = $follower->provider->$providerId;

                if (isset($provider->allocatedBalance)) {
                    $allocatedBalance = is_object($provider->allocatedBalance) ? $provider->allocatedBalance->__toString() : $provider->allocatedBalance;
                } else {
                    $allocatedBalance = 0;
                }
                if (isset($provider->profitsFromClosedBalance)) {
                    $profitsFromClosedBalance = is_object($provider->profitsFromClosedBalance) ? $provider->profitsFromClosedBalance->__toString() : $provider->profitsFromClosedBalance;
                } else {
                    $profitsFromClosedBalance = 0;
                }

                $decimals = $allocatedBalance > 1 ? 2 : 8;
                $parsedFollowers[] = [
                    'userId' => $follower->_id->__toString(),
                    'email' => $isAdmin ? $follower->email : $this->truncateEmail($follower->email),
                    'name' => $follower->firstName,
                    'realExchangeConnected' => $this->newUserModel->checkIfConnectedExchangeIsReal($follower, $providerId),
                    'connected' => empty($provider->disable) && !empty($provider->exchangeInternalId),
                    'active' => $this->checkIfProviderIsActive($provider, $follower, $providerParent),
                    'suspended' => !empty($provider->suspended),
                    'code' => isset($provider->customerKey) ? $provider->customerKey : '-',
                    'cancelDate' => $this->extractCancelDate($provider),
                    'lastTransactionId' => $this->extractLastTransactionId($provider),
                ];
            }
        } else {
            $userIdConnectedAt = [];
            $tempFollower = [];
            foreach ($followers as $follower) {
                $provider = $follower->provider->$providerId;
                foreach ($provider->exchangeInternalIds as $exchangeConnected) {
                    if (isset($exchangeConnected->disconnected) && ($exchangeConnected->disconnected)) {
                        continue;
                    }
                    $exchangeInternalId = $exchangeConnected->internalId;
                    $userIdExchangeId = $follower->_id->__toString() . ':' . $exchangeInternalId;
                    $userIdConnectedAt[] = [
                        $userIdExchangeId,
                    ];
                    $retain = empty($exchangeConnected->retain) ? 0 : $exchangeConnected->retain;
                    $profitsShare = $providerParent->profitsShare;
                    $profitsMode = empty($exchangeConnected->profitsMode) ? 'Unknown' : $exchangeConnected->profitsMode;
                    $tempFollower[] = [
                        'userId' => $follower->_id->__toString(),
                        'email' => $isAdmin ? $follower->email : $this->truncateEmail($follower->email),
                        'name' => $follower->firstName,
                        'realExchangeConnected' => $this->newUserModel->checkIfConnectedExchangeIsReal($follower, $providerId),
                        'connected' => empty($exchangeConnected->disconnected),
                        'active' => $this->checkIfProviderIsActive($provider, $follower, $providerParent),
                        'suspended' => !empty($provider->suspended),
                        'allocatedBalance' => 0,
                        'profitsFromClosedBalance' => 0,
                        'originalAllocated' => 0,
                        'retain' => $retain,
                        'profitsShare' => $profitsShare,
                        'profitsMode' => $profitsMode,
                        'code' => isset($provider->customerKey) ? $provider->customerKey : '-',
                        'cancelDate' => $this->extractCancelDate($provider),
                        'lastTransactionId' => $this->extractLastTransactionId($provider),
                        'unit' => $providerParent->quote,
                        'userExchangeId' => $userIdExchangeId
                    ];
                }
            }

            foreach ($tempFollower as $follower) {
                $userIdExchangeId = $follower['userExchangeId'];
                if (isset($userBalanceSummary[$userIdExchangeId])) {
                    $summary = $userBalanceSummary[$userIdExchangeId];
                    $follower['allocatedBalance'] = (float)(is_object($summary->balance) ? $summary->balance->__toString() : $summary->balance);
                    $follower['profitsFromClosedBalance'] = (float)(is_object($summary->profits) ? $summary->profits->__toString() : $summary->profits);
                    $follower['originalAllocated'] = (float)(is_object($summary->deposits) ? $summary->deposits->__toString() : $summary->deposits);
                }
                $parsedFollowers[] = $follower;
            }
        }

        return $parsedFollowers;
    }

    /**
     * Given a provider, check if the user has paid it (if needed).
     *
     * @param object $provider
     * @param BSONDocument $user
     * @param BSONDocument $providerParent
     * @return bool
     */
    private function checkIfProviderIsActive(object $provider, BSONDocument $user, BSONDocument $providerParent)
    {
        if (isset($user->stripe) && isset($user->stripe->planId) && $user->stripe->planId == '008') {
            return true;
        }

        $active = true;

        if (isset($providerParent->options) && !empty($providerParent->options->customerKey)) {
            if (isset($provider->enableInProvider)) {
                $active = $provider->enableInProvider;
            }
        }

        if (!empty($providerParent->internalPaymentInfo) && isset($provider->stripe) && isset($provider->stripe->enable)) {
            $active = $provider->stripe->enable;
        }

        return $active;
    }

    /**
     * Create a new provider service.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function createProvider(Request $request, array $payload)
    {
        if ($request->getMethod() !== 'POST') {
            sendHttpResponse(['error' => ['code' => 20]]);
        }

        list($user,) = $this->validateCommonConstraintsForProvider($payload, false);

        if (empty($payload['name']) || empty($payload['exchangeType'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        $name = filter_var($payload['name'], FILTER_SANITIZE_STRING);
        $exchangeType = filter_var($payload['exchangeType'], FILTER_SANITIZE_STRING);

        $projectId = empty($payload['projectId']) ? 'z01' : filter_var($payload['projectId'], FILTER_SANITIZE_STRING);

        $availableOptions = [
            "stopLossFromSignal",
            "acceptUpdateSignal",
            "takeProfitsFromSignal",
            "trailingStopFromSignal",
            "enableSellSignals",
            "enablePanicSellSignals",
            "allowSendingBuyOrdersAsMarket",
            "reBuyFromProvider",
            "reUseSignalIdIfClosed",
            "terms",
            "riskFilter",
            "limitPriceFromSignal",
            "successRateFilter",
            "reBuysFromSignal",
            "useLeverageFromSignal",
            "allowClones",
            "disclaimer",
            "acceptReduceOrders",
            "customerKey",
            //"allowLimitPrice",
            //"unfriendly",
            //"balanceFilter",
        ];

        $options = [];
        foreach ($availableOptions as $availableOption) {
            if (!empty($payload[$availableOption])) {
                if ($availableOption == 'disclaimer') {
                    $options[$availableOption] = filter_var($payload['disclaimer'], FILTER_SANITIZE_STRING);
                } else {
                    $options[$availableOption] = true;
                }
            } else {
                $options[$availableOption] = false;
            }
        }

        $exchanges = [];
        if (!empty($payload['exchanges'])) {
            foreach ($payload['exchanges'] as $exchange) {
                $exchanges[] = strtolower(filter_var($exchange, FILTER_SANITIZE_STRING));
            }
            if (in_array('binance', $exchanges) && !in_array('zignaly', $exchanges)) {
                $exchanges[] = 'zignaly';
            }
            if (in_array('zignaly', $exchanges) && !in_array('binance', $exchanges)) {
                $exchanges[] = 'binance';
            }
        }

        $quotes = [];
        if (!empty($payload['quotes'])) {
            foreach ($payload['quotes'] as $quote) {
                $quotes[] = strtoupper(filter_var($quote, FILTER_SANITIZE_STRING));
            }
        }

        $provider = [
            'name' => $name,
            'key' => md5(microtime() . $user->_id->__toString() . rand(2342, 999999999999)),
            'userId' => $user->_id,
            'projectId' => $projectId,
            'exchanges' => $exchanges,
            'exchangeType' => $exchangeType,
            'quotes' => $quotes,
            'disable' => false,
            'options' => $options,
            'public' => false,
            'list' => false,
            'signalsLastUpdate' => new \MongoDB\BSON\UTCDateTime(),
            'updatingSignal' => false,
            'disabledMarkets' => [],
            'locked' => false,
            'lockedAt' => new \MongoDB\BSON\UTCDateTime(),
            'lockedBy' => false,
            'lockedFrom' => gethostname(),
            'activeSince' => time(),
            'about' => false,
            'followers' => 0,
            'social' => [],
            'team' => [],
        ];

        return new JsonResponse($this->providerModel->createProviderService($provider));
    }

    /**
     * Extract the last transactionId if it exists.
     *
     * @param object $provider
     * @return string
     */
    private function extractLastTransactionId(object $provider)
    {
        $lastTransactionId = '-';

        if (isset($provider->stripe) && isset($provider->stripe->txIds) && is_array($provider->stripe->txIds)) {
            foreach ($provider->stripe->txIds as $txId) {
                $lastTransactionId = $txId;
            }
        }

        return $lastTransactionId;
    }

    /**
     * Check if the cancelDate is active and return it.
     *
     * @param object $provider
     * @return string
     */
    private function extractCancelDate(object $provider)
    {
        $cancelDate = '-';
        if (isset($provider->stripe)) {
            if (!empty($provider->stripe->cancelAtPeriodEnd)) {
                if (isset($provider->stripe->cancelDate) && is_object($provider->stripe->cancelDate)) {
                    $cancelDate = $provider->stripe->cancelDate->__toString();
                }
            }
        }

        return $cancelDate;
    }

    /**
     * Compose the provider/copy-trader data and send it back, or error if it doesn't exists or is not accessible by
     * this user.
     *
     * @param Request $request
     * @param $payload
     */
    public function getProvider(Request $request, $payload)
    {
        list($user, $provider) = $this->validateCommonConstraintsForProvider($payload);
        $providerInfo = $this->parseProviderInfo($provider, $user->_id);
        $userInfo = $this->parseProviderUserInfo($providerInfo, $user, $payload);
        $providerInfo['performance'] = $this->parseProviderPerformance($provider->_id->__toString(), 12);
        $userProviderInfo = array_merge($userInfo, $providerInfo);
        $userProviderInfo['key'] = $providerInfo['isAdmin'] ? $provider->key : false;

        sendHttpResponse($userProviderInfo);
    }

    /**
     * Retrieve the provider's performance data and send it back.
     *
     * @param Request $request
     * @param $payload
     * @return JsonResponse
     */
    public function getProviderPerformance(Request $request, $payload)
    {
        list(, $provider) = $this->validateCommonConstraintsForProvider($payload);

        return new JsonResponse($this->parseProviderPerformance($provider->_id->__toString()));
    }

    /**
     * Get performance stats from provider.
     *
     * @param string $providerId
     * @param bool|int $limit
     * @return array
     */
    private function parseProviderPerformance(string $providerId, $limit = false)
    {
        $key = $providerId . '_performance';
        $data = $this->RedisPublicCache->getKey($key);
        $performance = json_decode($data, true);

        if (!isset($performance['openPositions'])) {
            $performance = [
                'openPositions' => 0,
                'closePositions' => 0,
                'totalBalance' => 0,
                'totalTradingVolume' => 0,
                'weeklyStats' => [],
            ];
        }

        if ($limit) {
            if (!empty($performance['weeklyStats'])) {
                $performance['weeklyStats'] = array_slice($performance['weeklyStats'], 0, $limit);
            }
        }

        return $performance;
    }

    /**
     * Generate the specific info from the user for the provider data.
     *
     * @param array $providerInfo
     * @param BSONDocument $user
     * @param array $payload
     * @return array
     */
    private function parseProviderUserInfo(array $providerInfo, BSONDocument $user, array $payload)
    {
        $providerId = $providerInfo['id'];
        if (isset($user->provider->$providerId)) {
            $userInfo = $user->provider->$providerId->getArrayCopy();
        } else {
            $userInfo = [];
        }
        if ($providerInfo['internalPaymentInfo'] !== false && !isset($userInfo['userPaymentInfo']['userId'])) {
            $userInfo['userPaymentInfo']['userId'] = $user->_id->__toString();
        }
        if (isset($userInfo['terms']) && $userInfo['terms']) {
            $termTypes = ['short', 'shortmid', 'mid', 'long'];
            foreach ($termTypes as $termType) {
                if (in_array($termType, $userInfo['terms']->getArrayCopy())) {
                    $userInfo[$termType] = true;
                }
            }
        }

        if (isset($userInfo['reBuyFromProvider']) && $userInfo['reBuyFromProvider']) {
            $userInfo['quantityPercentage'] = $userInfo['reBuyFromProvider']['quantityPercentage'] * 100;
            $userInfo['limitReBuys'] = $userInfo['reBuyFromProvider']['limitReBuys'];
            $userInfo['reBuyFromProvider'] = true;
        }

        if (!empty($user->provider->$providerId->profitsMode)) {
            $exchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);
            if (empty($payload['exchangeInternalId'])) {
                $userInfo['allocatedBalance'] = 0;
                $userInfo['originalBalance'] = 0;
                $userInfo['profitsFromClosedBalance'] = 0;
            } else {
            }
            list($userInfo['disable'], $userInfo['connected']) = $this->checkIfTheInternalExchangeIdIsConnected(
                $user->provider->$providerId,
                $exchangeInternalId
            );
            if (!$userInfo['connected']) {
                $userInfo['exchangeInternalIds'] = [];
            }
        } elseif (isset($userInfo['allocatedBalance']) && isset($userInfo['profitsFromClosedBalance'])) {
            $allocatedBalance = is_object($userInfo['allocatedBalance']) ?
                $userInfo['allocatedBalance']->__toString() : $userInfo['allocatedBalance'];
            $profitsFromClosedBalance = is_object($userInfo['profitsFromClosedBalance']) ?
                $userInfo['profitsFromClosedBalance']->__toString() : $userInfo['profitsFromClosedBalance'];
            $userInfo['allocatedBalance'] = $allocatedBalance + $profitsFromClosedBalance;
            $userInfo['originalBalance'] = $allocatedBalance;
            $userInfo['profitsFromClosedBalance'] = $profitsFromClosedBalance;
            $userInfo['connected'] = empty($userInfo['disable']) && !empty($userInfo['exchangeInternalId']);
            if (!isset($userInfo['disable'])) {
                $userInfo['disable'] = true;
            }
        }

        $userInfo['hasBeenUsed'] = isset($user->provider->$providerId);
        if (isset($userInfo['stripe'])) {
            if (isset($userInfo['stripe']['cancelDate']) && is_object($userInfo['stripe']['cancelDate'])) {
                $userInfo['stripe']['cancelDate'] = $userInfo['stripe']['cancelDate']->__toString();
            }
            if (isset($userInfo['stripe']['trialStartedAt']) && is_object($userInfo['stripe']['trialStartedAt'])) {
                $userInfo['stripe']['trialStartedAt'] = $userInfo['stripe']['trialStartedAt']->__toString();
            }
        }


        if (!isset($userInfo['exchangeInternalId'])) {
            $userInfo['exchangeInternalId'] = false;
        }

        $userInfo['userConnectedAt'] = empty($userInfo['createdAt']) ? false : $userInfo['createdAt']->__toString();
        unset($userInfo['_id']);

        if (isset($userInfo['createdAt'])) {
            unset($userInfo['createdAt']);
        }

        $userInfo['notificationsPosts'] = isset($userInfo['notificationsPosts']) ? $userInfo['notificationsPosts'] : false;

        return $userInfo;
    }

    /**
     * Check if the current exchange is connected to the service.
     * @param object $provider
     * @param string $exchangeInternalId
     * @return array
     */
    private function checkIfTheInternalExchangeIdIsConnected(object $provider, string $exchangeInternalId)
    {
        if (empty($provider->exchangeInternalIds)) {
            $disable = true;
            $connected = false;
        } else {
            foreach ($provider->exchangeInternalIds as $connection) {
                if ($connection->internalId === $exchangeInternalId) {
                    $disable = !empty($connection->disconnected);
                    $connected = empty($connection->disconnected);
                }
            }
        }
        if (!isset($disable) || !isset($connected)) {
            $disable = true;
            $connected = false;
        }

        return [$disable, $connected];
    }

    /**
     * Prepare the returned provider array.
     *
     * @param BSONDocument $provider
     * @param ObjectId $userId
     * @return array
     */
    private function parseProviderInfo(BSONDocument $provider, ObjectId $userId)
    {
        $providerInfo =  [
            'id' => $provider->_id->__toString(),
            'name' => $provider->name,
            'about' => empty($provider->about) ? '' : $provider->about,
            'strategy' => empty($provider->strategy) ? '' : $provider->strategy,
            'logoUrl' => empty($provider->logoUrl) ? 'images/providersLogo/default.png' : $provider->logoUrl,
            'team' => $this->getTeam($provider),
            'social' => $this->getSocial($provider),
            'exchanges' => empty($provider->exchanges) ? [] : $provider->exchanges->getArrayCopy(),
            'isAdmin' => $userId == $provider->userId ? true : false,
            'isClone' => isset($provider->clonedFrom),
            'isCopyTrading' => !empty($provider->isCopyTrading),
            'profitsShare' => !empty($provider->profitsShare) ? $provider->profitsShare : 0,
            'copyTradingQuote' => isset($provider->quote) ? $provider->quote : false,
            'signalProviderQuotes' => empty($provider->quotes) ? [] : $provider->quotes->getArrayCopy(),
            'minAllocatedBalance' => isset($provider->minAllocatedBalance) ? $provider->minAllocatedBalance : 0,
            'list' => !empty($provider->list),
            'public' => !empty($provider->public),
            'exchangeType' => empty($provider->exchangeType) ? "spot" : $provider->exchangeType,
            'website' => empty($provider->website) ? false : $provider->website,
            'internalPaymentInfo' => isset($provider->internalPaymentInfo) && isset($provider->internalPaymentInfo->price)
                && $provider->internalPaymentInfo->price > 0 ? $provider->internalPaymentInfo : false,
            'avgTradesPerWeek' => empty($provider->avgTradesPerWeek) ? 0 : (float)$provider->avgTradesPerWeek,
            'avgHoldingTime' => empty($provider->avgHoldingTime) ? 0 : (int)$provider->avgHoldingTime,
            'activeSince' => empty($provider->activeSince) ? 0 : $provider->activeSince,
            'profitableWeeks' => empty($provider->profitableWeeks) ? 0 : (float)$provider->profitableWeeks,
            'followers' => empty($provider->followers) ? 0 : (int) $provider->followers,
            'price' => $this->extractPrice($provider),
        ];

        $optionsAvailable = count((array)$provider->options);

        if (isset($provider->clonedFrom)) {
            $providerInfo['options'] =  $optionsAvailable ? $provider->options : $this->getParentProviderOptions($userId, $provider->clonedFrom);
        } else {
            $providerInfo['options'] = $optionsAvailable ? $provider->options : false;
        }

        if (isset($providerInfo['internalPaymentInfo']['ipnSecret'])) {
            unset($providerInfo['internalPaymentInfo']['ipnSecret']);
        }

        if (isset($provider['createdAt'])) {
            unset($provider['createdAt']);
        }

        return $providerInfo;
    }

    /**
     * Prepare the returned provider options array.
     *
     * @param ObjectId $userId
     * @param ObjectId $clonedFromId
     * @return array
     */
    public function getParentProviderOptions(ObjectId $userId, ObjectId $clonedFromId)
    {
        $clonedFromProvider = $this->providerModel->getProvider($userId, $clonedFromId);
        return $clonedFromProvider->options;
    }

    /**
     * Extract the provider's price, or 0 if any.
     *
     * @param BSONDocument $provider
     * @return int
     */
    private function extractPrice(BSONDocument $provider)
    {
        $price = 0;

        if (!empty($provider->internalPaymentInfo)) {
            if (!empty($provider->internalPaymentInfo->price)) {
                $price = $provider->internalPaymentInfo->price;
            }
        }

        if (empty($price)) {
            if (!empty($provider->options->customerKey) && !empty($provider->fee)) {
                $price = $provider->fee;
            }
        }

        return (int) $price;
    }

    /**
     * Get the social networks and return them.
     *
     * @param BSONDocument $provider
     * @return array|bool
     */
    private function getSocial(BSONDocument $provider)
    {
        if (empty($provider->social)) {
            return false;
        }

        $social = [];
        foreach ($provider->social as $item) {
            $social[] = [
                'network' => $item->network,
                'link' => $item->link
            ];
        }

        return $social;
    }

    /**
     * Get the team members and return them.
     *
     * @param BSONDocument $provider
     * @return array|bool
     */
    private function getTeam(BSONDocument $provider)
    {
        if (empty($provider->team)) {
            return false;
        }

        $team = [];
        foreach ($provider->team as $member) {
            $team[] = [
                'name' => $member->name,
                'countryCode' => $member->countryCode
            ];
        }

        return $team;
    }

    /**
     * Given a full email address truncate it to show less characters.
     *
     * @param string $email
     * @return string
     */
    private function truncateEmail(string $email)
    {
        $emailMainParts = explode('@', $email);
        $username = $emailMainParts[0];
        $domain = $emailMainParts[1];
        $domainParts = explode('.', $domain);
        $domainName = $domainParts[0];
        array_shift($domainParts);
        $parsedUsername = substr($username, 0, 2);
        $parsedDomain = substr($domainName, 0, 2);
        $parsedTLD = implode('.', $domainParts);

        $truncatedEmail = "$parsedUsername***@$parsedDomain***.$parsedTLD";

        return $truncatedEmail;
    }


    /**
     * Update provider exchange settings.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function updateProviderExchangeSettings(Request $request, $payload)
    {
        if ($request->getMethod() !== 'POST') {
            sendHttpResponse(['error' => ['code' => 20]]);
        }

        $token = checkSessionIsActive();

        $user = $this->userModel->getUser($token);
        if (isset($user['error'])) {
            sendHttpResponse($user);
        }

        $providerId = isset($payload['providerId']) ? filter_var($payload['providerId'], FILTER_SANITIZE_STRING)
            : false;
        if (!$providerId) {
            sendHttpResponse(['error' => ['code' => 30]]);
        }

        if (!isset($user->provider->$providerId)) {
            sendHttpResponse(['error' => ['code' => 56]]);
        }

        if (!isset($payload['internalExchangeId'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        $internalId = filter_var($payload['internalExchangeId'], FILTER_SANITIZE_STRING);

        foreach ($user->exchanges as $exchange) {
            if ($exchange->internalId == $internalId) {
                $internalName = $exchange->internalName;
                $name = $exchange->name;
                $exchangeId = $exchange->exchangeId;
                $exchangeMediator = ExchangeMediator::fromMongoExchange($exchange);
                $exchangeType = $exchangeMediator->getExchangeType();
            }
        }

        unset($exchange);

        $exchange['_id'] = new \MongoDB\BSON\ObjectId($exchangeId);
        $exchange['internalId'] = $internalId;
        $exchange['internalName'] = $internalName;
        $exchange['name'] = $name;
        $numericFields = [
            'trailingStopTrigger',
            'trailingStop',
            'stopLoss',
            'buyTTL',
            'sellByTTL',
            'maxPositions',
            'minVolume',
            'positionsPerMarket',
            'globalMaxPositions',
            'globalMinVolume',
            'globalPositionsPerMarket',
            'priceDeviation',
            'sellPriceDeviation',
            'leverage'
        ];

        foreach ($numericFields as $numericField) {
            if (isset($payload[$numericField])) {
                $tmpKey = trim(filter_var($payload[$numericField], FILTER_SANITIZE_STRING));
                $exchange[$numericField] = $tmpKey == 0 ? false : $tmpKey;
                if ($numericField == 'buyTTL') {
                    $exchange[$numericField] = $exchange[$numericField] * 60;
                }
                if ($numericField == 'sellByTTL') {
                    $exchange[$numericField] = $exchange[$numericField] * 60 * 60;
                }
            }
        }

        $multiTargets = ['takeProfitTargets', 'reBuyTargets'];
        foreach ($multiTargets as $multiTarget) {
            if (isset($parsedTargets)) {
                unset($parsedTargets);
            }

            if (isset($payload[$multiTarget])) {
                if ($payload[$multiTarget] !== false) {
                    $totalAmount = 0;
                    $targetId = 0;
                    foreach ($payload[$multiTarget] as $target) {
                        $amount = (float)trim(filter_var($target['amountPercentage'], FILTER_SANITIZE_STRING));
                        $targetId++;
                        $parsedTargets[$targetId] = [
                            'targetId' => $targetId,
                            'priceTargetPercentage' => trim(
                                filter_var(
                                    $target['priceTargetPercentage'],
                                    FILTER_SANITIZE_STRING
                                )
                            ),
                            'amountPercentage' => trim(
                                filter_var($target['amountPercentage'], FILTER_SANITIZE_STRING)
                            ),
                            'postOnly' => filter_var($target['postOnly'], FILTER_VALIDATE_BOOLEAN) === true
                        ];
                        $totalAmount = $totalAmount + $amount;
                    }
                }

                $exchange[$multiTarget] = !isset($parsedTargets) ? false : $parsedTargets;
            }
        }

        $filterLists = ['blacklist', 'whitelist'];
        $realExchangeName = ZignalyExchangeCodes::getRealExchangeName($name);
        $RedisHandlerZignalyData = null;
        $marketDataService = null;
        $marketEncoder = BaseMarketEncoder::newInstance($name, $exchangeType);
        foreach ($filterLists as $filterList) {
            if (isset($payload[$filterList])) {
                if (!$payload[$filterList] || empty($payload[$filterList])) {
                    $exchange[$filterList] = false;
                } else {
                    $symbols = explode(',', filter_var($payload[$filterList], FILTER_SANITIZE_STRING));
                    $listedSymbols = [];
                    if (count($symbols) > 0) {
                        if ($marketDataService == null) {
                            $RedisHandlerZignalyData = new RedisHandler($this->monolog, 'ZignalyData');
                            $marketDataService = new ZignalyMarketDataRedisService(
                                $RedisHandlerZignalyData,
                                $this->monolog
                            );
                        }
                        foreach ($symbols as $symbol) {
                            $symbol = $marketEncoder->withoutSlash(trim($symbol));
                            // if ($BinanceSymbolFE->checkIfSymbolExists($symbol))
                            $symbolInfo = $marketDataService->getMarket($name, $symbol);

                            if ($symbolInfo) {
                                $listedSymbols[] = $symbol;
                            }
                        }
                    }
                    $exchange[$filterList] = count($listedSymbols) == 0 ? false : $listedSymbols;
                }
            }
        }

        $quotes = $marketEncoder->getValidQuoteAssets4PositionSizeExchangeSettings();
        $positionsSize = [];
        foreach ($quotes as $quote) {
            $positionSizeValue = 'positionSize' . $quote . 'Value';
            $positionSizeUnit = 'positionSize' . $quote . 'Unit';
            $value = isset($payload[$positionSizeValue])
                ? trim(filter_var($payload[$positionSizeValue], FILTER_SANITIZE_STRING)) : false;
            $unit = isset($payload[$positionSizeUnit])
                ? trim(filter_var($payload[$positionSizeUnit], FILTER_SANITIZE_STRING)) : false;

            if ($value === false) {
                $value = isset($user->provider->$providerId->exchange->$exchangeId->positionsSize->$quote->value)
                    ? $user->provider->$providerId->exchange->$exchangeId->positionsSize->$quote->value : 0;
            }
            if (!$unit) {
                $unit = isset($user->provider->$providerId->exchange->$exchangeId->positionsSize->$quote->unit)
                    ? $user->provider->$providerId->exchange->$exchangeId->positionsSize->$quote->unit : '#';
            }

            $positionsSize[$quote] = [
                'quote' => $quote,
                'value' => $value,
                'unit' => $unit,
            ];
        }
        if (count($positionsSize) > 0) {
            $exchange['positionsSize'] = $positionsSize;
        }

        $exchange['disable'] = isset($payload['disable']) && $payload['disable'] ? true : false;

        $exchange['allowedSide'] = empty($payload['allowedSide'])
            || !in_array($payload['allowedSide'], ['long', 'short', 'both'])
            ? false : $payload['allowedSide'];

        $isNew = isset($user->provider->$providerId->exchange->$exchangeId) ? false : true;

        $this->userModel->tmpTransitionToExchanges($user, $exchange, $providerId);
        $exchangeSettings = $this->userModel->updateExchange($user->_id, $exchange, $providerId, $isNew);
        $newSet = [
            'provider.' . $providerId . '.exchangeInternalId' => $internalId
        ];
        $this->userModel->updateUser($user->_id, $newSet);

        $event = [
            'type' => 'configureServiceSettings',
            'userId' => $user->_id->__toString(),
            'parameters' => [
                'providerId' => $providerId,
                'settings' => $exchange,
                'exchangeInternalId' => $internalId,
            ],
            'timestamp' => time(),
        ];
        $this->RedisHandlerZignalyQueue->insertElementInList('eventsQueue', json_encode($event));


        sendHttpResponse($exchangeSettings);
    }

    /**
     * Update provider exchange settings Version 2.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function updateProviderExchangeSettingsV2(Request $request, array $payload)
    {
        if ($request->getMethod() !== 'POST') {
            sendHttpResponse(['error' => ['code' => 20]]);
        }

        list($user, $provider) = $this->validateCommonConstraintsForProvider($payload);

        if (!isset($payload['internalExchangeId'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        $internalId = filter_var($payload['internalExchangeId'], FILTER_SANITIZE_STRING);

        foreach ($user->exchanges as $exchange) {
            if ($exchange->internalId == $internalId) {
                $internalName = $exchange->internalName;
                $name = $exchange->name;
                $exchangeId = $exchange->exchangeId;
                $exchangeMediator = ExchangeMediator::fromMongoExchange($exchange);
                $exchangeType = $exchangeMediator->getExchangeType();
            }
        }

        if (empty($internalName)) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        unset($exchange);

        $exchange['_id'] = new \MongoDB\BSON\ObjectId($exchangeId);
        $exchange['internalId'] = $internalId;
        $exchange['internalName'] = $internalName;
        $exchange['name'] = $name;
        $numericFields = [
            'trailingStopTrigger',
            'trailingStop',
            'stopLoss',
            'buyTTL',
            'sellByTTL',
            'maxPositions',
            'minVolume',
            'positionsPerMarket',
            'globalMaxPositions',
            'globalMinVolume',
            'globalPositionsPerMarket',
            'priceDeviation',
            'sellPriceDeviation',
            'leverage'
        ];

        $toFactorizedFields = ['trailingStopTrigger', 'trailingStop', 'stopLoss', 'priceDeviation', 'sellPriceDeviation'];
        foreach ($numericFields as $numericField) {
            if (isset($payload[$numericField])) {
                $tmpKey = trim(filter_var($payload[$numericField], FILTER_SANITIZE_STRING));
                $exchange[$numericField] = $tmpKey == 0 ? false : $tmpKey;
                if (in_array($numericField, $toFactorizedFields)) {
                    $exchange[$numericField] = $this->composeFactor($tmpKey);
                }
            }
        }

        $multiTargets = ['takeProfitTargets', 'reBuyTargets'];
        foreach ($multiTargets as $multiTarget) {
            if (isset($parsedTargets)) {
                unset($parsedTargets);
            }

            if (isset($payload[$multiTarget])) {
                if ($payload[$multiTarget] !== false) {
                    $totalAmount = 0;
                    $targetId = 0;
                    foreach ($payload[$multiTarget] as $target) {
                        $amount = (float)trim(filter_var($target['amountPercentage'], FILTER_SANITIZE_STRING));
                        $target = (float)trim(filter_var($target['priceTargetPercentage'], FILTER_SANITIZE_STRING));
                        $targetId++;
                        $totalAmount += $amount;
                        if ($multiTarget == 'reBuyTargets' || $totalAmount <= 100) {
                            $parsedTargets[$targetId] = [
                                'targetId' => $targetId,
                                'priceTargetPercentage' => $this->composeFactor($target),
                                'amountPercentage' => $amount / 100,
                                'postOnly' => filter_var($target['postOnly'], FILTER_VALIDATE_BOOLEAN) === true
                            ];
                        }
                    }
                }
                $exchange[$multiTarget] = !isset($parsedTargets) ? false : $parsedTargets;
            }
        }

        $filterLists = ['blacklist', 'whitelist'];
        $RedisHandlerZignalyData = null;
        $marketDataService = null;
        $marketEncoder = BaseMarketEncoder::newInstance($name, $exchangeType);
        foreach ($filterLists as $filterList) {
            if (isset($payload[$filterList])) {
                if (empty($payload[$filterList])) {
                    $exchange[$filterList] = false;
                } else {
                    $symbols = explode(',', filter_var($payload[$filterList], FILTER_SANITIZE_STRING));
                    $listedSymbols = [];
                    if (count($symbols) > 0) {
                        if ($marketDataService == null) {
                            $RedisHandlerZignalyData = new RedisHandler($this->monolog, 'ZignalyData');
                            $marketDataService = new ZignalyMarketDataRedisService(
                                $RedisHandlerZignalyData,
                                $this->monolog
                            );
                        }
                        foreach ($symbols as $symbol) {
                            $symbol = $marketEncoder->withoutSlash(trim($symbol));
                            $symbolInfo = $marketDataService->getMarket($name, $symbol);
                            if ($symbolInfo) {
                                $listedSymbols[] = $symbol;
                            }
                        }
                    }
                    $exchange[$filterList] = count($listedSymbols) == 0 ? false : $listedSymbols;
                }
            }
        }

        $quotes = $marketEncoder->getValidQuoteAssets4PositionSizeExchangeSettings();
        $positionsSize = [];
        foreach ($quotes as $quote) {
            $positionSizeValue = 'positionSize' . $quote . 'Value';
            $positionSizeUnit = 'positionSize' . $quote . 'Unit';
            $value = !empty($payload[$positionSizeValue])
                ? trim(filter_var($payload[$positionSizeValue], FILTER_SANITIZE_STRING)) : false;
            $unit = !empty($payload[$positionSizeUnit])
                ? trim(filter_var($payload[$positionSizeUnit], FILTER_SANITIZE_STRING)) : false;

            if ($value && $unit) {
                $positionsSize[$quote] = [
                    'quote' => $quote,
                    'value' => $value,
                    'unit' => $unit,
                ];
            }
        }
        if (count($positionsSize) > 0) {
            $exchange['positionsSize'] = $positionsSize;
        }

        $exchange['disable'] = empty($payload['disable']) ? false : $payload['disable'];

        $exchange['allowedSide'] = empty($payload['allowedSide'])
            || !in_array($payload['allowedSide'], ['long', 'short', 'both'])
            ? false : $payload['allowedSide'];

        $providerId = $provider->_id->__toString();

        $updateUser = $this->userModel->updateProviderExchangeSettings($user->_id, $exchange, $providerId);

        if (empty($updateUser->provider->$providerId->exchanges)) {
            sendHttpResponse(['error' => ['code' => 17]]);
        }

        foreach ($updateUser->provider->$providerId->exchanges as $providerExchange) {
            $updatedExchange = $providerExchange;
        }

        if (empty($updatedExchange)) {
            sendHttpResponse(['error' => ['code' => 17]]);
        }

        $event = [
            'type' => 'configureServiceSettings',
            'userId' => $user->_id->__toString(),
            'parameters' => [
                'providerId' => $providerId,
                'settings' => $exchange,
                'exchangeInternalId' => $internalId,
            ],
            'timestamp' => time(),
        ];
        $this->RedisHandlerZignalyQueue->insertElementInList('eventsQueue', json_encode($event));

        return new JsonResponse($this->parseExchangeSettings($updatedExchange));
    }

    /**
     * Retrieve provider exchange settings Version 2.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getProviderExchangeSettings(Request $request, array $payload)
    {
        list($user, $provider) = $this->validateCommonConstraintsForProvider($payload);

        if (!isset($payload['internalExchangeId'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        $internalId = filter_var($payload['internalExchangeId'], FILTER_SANITIZE_STRING);

        foreach ($user->exchanges as $exchange) {
            if ($exchange->internalId == $internalId) {
                $exchangeStillActive = true;
            }
        }

        if (empty($exchangeStillActive)) {
            sendHttpResponse([]);
        }

        $providerId = $provider->_id->__toString();

        if (empty($user->provider->$providerId->exchanges)) {
            sendHttpResponse([]);
        }

        foreach ($user->provider->$providerId->exchanges as $providerExchange) {
            $updatedExchange = $providerExchange;
        }

        if (empty($updatedExchange)) {
            sendHttpResponse([]);
        }

        return new JsonResponse($this->parseExchangeSettings($updatedExchange));
    }

    /**
     * Prepare the data for the frontend.
     *
     * @param object $exchangeSettings
     * @return object
     */
    private function parseExchangeSettings(object $exchangeSettings)
    {
        $filterLists = ['blacklist', 'whitelist'];
        foreach ($filterLists as $filterList) {
            if (!empty($exchangeSettings->$filterList)) {
                $filterListSymbols = '';
                foreach ($exchangeSettings->$filterList as $symbol) {
                    $filterListSymbols .= $symbol . ', ';
                }

                $exchangeSettings->$filterList = rtrim($filterListSymbols, ', ');
            }
        }

        if (isset($exchangeSettings->positionsSize)) {
            foreach ($exchangeSettings->positionsSize as $positionSize) {
                $quote = $positionSize->quote;
                $name = 'positionSize' . $quote;
                $valueName = $name . 'Value';
                $unitName = $name . 'Unit';
                $exchangeSettings->$valueName = $positionSize->value;
                $exchangeSettings->$unitName = $positionSize->unit;
            }
            unset($exchangeSettings->positionsSize);
        }

        $targetsGroups = ['takeProfitTargets', 'reBuyTargets'];
        foreach ($targetsGroups as $targetGroup) {
            if (!empty($exchangeSettings->$targetGroup)) {
                foreach ($exchangeSettings->$targetGroup as $target) {
                    $targetId = $target->targetId;
                    $exchangeSettings->$targetGroup->$targetId->priceTargetPercentage =
                        $this->deComposeFactor($exchangeSettings->$targetGroup->$targetId->priceTargetPercentage);
                    $exchangeSettings->$targetGroup->$targetId->amountPercentage =
                        $exchangeSettings->$targetGroup->$targetId->amountPercentage * 100;
                }
            }
        }

        $factorizedParameters = ['trailingStopTrigger', 'trailingStop', 'stopLoss', 'priceDeviation', 'sellPriceDeviation'];
        foreach ($factorizedParameters as $parameter) {
            if (!empty($exchangeSettings->$parameter)) {
                $exchangeSettings->$parameter = $this->deComposeFactor($exchangeSettings->$parameter);
            }
        }

        return $exchangeSettings;
    }

    /**
     * Given a factor number get it's percentage value.
     *
     * @param $factor
     * @return bool|float|int
     */
    private function deComposeFactor($factor)
    {
        if (!$factor || !is_numeric($factor)) {
            return false;
        }

        return round(($factor - 1) * 100, 2);
    }

    /**
     * Get the given percentage and return its factor.
     *
     * @param bool|float|int $percentage
     * @return bool|float|int
     */
    private function composeFactor($percentage)
    {
        if (!$percentage || !is_numeric($percentage)) {
            return false;
        }

        return 1 + $percentage / 100;
    }

    /**
     * Subscribe to provider's posts notifications.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function updatePostsNotifications(Request $request, array $payload)
    {
        list($user) = $this->validateCommonConstraintsForProvider($payload, false);

        $providerId = isset($payload['providerId']) ? filter_var($payload['providerId'], FILTER_SANITIZE_STRING)
            : false;
        if (!$providerId) {
            sendHttpResponse(['error' => ['code' => 30]]);
        }

        if (!isset($payload['subscribed'])) {
            sendHttpResponse(['error' => ['code' => 31]]);
        }

        // return new JsonResponse($this->userModel->updatePostsNotifications($user->_id, $providerId, $payload['subscribed']));
        $provider = $this->providerModel->getProvider($user->_id, $providerId);
        $data = ['notificationsPosts' => $payload['subscribed']];
        return new JsonResponse($this->userModel->updateProvider($payload['token'], $provider, $data));
    }

    /**
     * Validate that request pass expected contraints for managing followers subscription
     *
     * @param array $payload Request payload.
     *
     * @return array
     */
    private function validateCommonConstraintsForFollowersSubscriptionActions(Request $request, array $payload)
    {
        if ($request->getMethod() !== 'POST') {
            sendHttpResponse(['error' => ['code' => 20]]);
        }

        $token = checkSessionIsActive();

        $user = $this->userModel->getUser($token);
        if (isset($user['error'])) {
            sendHttpResponse($user);
        }

        $providerId = isset($payload['providerId']) ? filter_var($payload['providerId'], FILTER_SANITIZE_STRING)
            : false;
        if (!$providerId) {
            sendHttpResponse(['error' => ['code' => 30]]);
        }

        if (!isset($user->provider->$providerId)) {
            sendHttpResponse(['error' => ['code' => 56]]);
        }

        $provider = $this->providerModel->getProvider($user->_id, $providerId);

        if (isset($provider['error']))
            sendHttpResponse($provider);

        if ($provider->userId != $user->_id)
            sendHttpResponse(['error' => ['code' => 58]]);

        $followerId = isset($payload['followerId']) ? filter_var($payload['followerId'], FILTER_SANITIZE_STRING)
            : false;
        if (!$followerId) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }
        $follower = $this->userModel->getUserById($followerId);
        if (isset($follower['error'])) {
            sendHttpResponse($user);
        }

        return [$user, $provider, $follower];
    }

    /**
     * Validate that request pass expected contraints for managing followers subscription
     *
     * @param array $payload Request payload.
     * @param bool $validateProvider
     *
     * @return array
     */
    private function validateCommonConstraintsForProvider(array $payload, $validateProvider = true)
    {
        $token = checkSessionIsActive();

        $user = $this->userModel->getUser($token);
        if (isset($user['error'])) {
            sendHttpResponse($user);
        }

        if ($validateProvider) {
            $providerId = isset($payload['providerId']) ? filter_var($payload['providerId'], FILTER_SANITIZE_STRING)
                : false;
            if (!$providerId) {
                sendHttpResponse(['error' => ['code' => 30]]);
            }

            $provider = $this->providerModel->getProvider($user->_id, $providerId);

            if (isset($provider['error']))
                sendHttpResponse($provider);
        } else {
            $provider = false;
        }

        return [$user, $provider];
    }
}
