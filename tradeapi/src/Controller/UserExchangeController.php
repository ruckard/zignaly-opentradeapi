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

use MongoDB\Model\BSONDocument;
use Monolog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Zignaly\Balance\BalanceService;
use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Process\DIContainer;
use Zignaly\redis\ZignalyMarketDataRedisService;

/**
 * Class UserExchangeController
 * @package Zignaly\Controller
 */
class UserExchangeController
{
    /**
     * User model.
     *
     * @var \UserFE
     */
    private $userModel;

    /**
     * Exchange calls controller.
     *
     * @var \ExchangeCalls
     */
    private $exchangeCalls;

    /**
     * User repository.
     *
     * @var \User
     */
    private $userRepository;

    /**
     * Monolog service.
     *
     * @var \Monolog
     */
    private $monolog;

    /**
     * Market data service.
     *
     * @var ZignalyMarketDataRedisService
     */
    private $marketData;

    /**
     * newPositionCCXT model
     *
     * @var \newPositionCCXT
     */
    private $newPositionCCXT;

    /**
     * Provider model.
     *
     * @var \ProviderFE
     */
    private $providerModel;

    /**
     * @var BalanceService
     */
    private $balanceService;

    /**
     * UserExchangeController constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        $container = DIContainer::getContainer();
        $this->userModel = new \UserFE();
        $this->userRepository = new \User();
        if (!$container->has('monolog')) {
            $container->set('monolog', new Monolog('UserExchangeController'));
        }
        $this->monolog = $container->get('monolog');
        $this->exchangeCalls = new \ExchangeCalls($this->monolog);
        $this->marketData = $container->get('marketData');
        $this->newPositionCCXT = $container->get('newPositionCCXT.model');
        $this->newPositionCCXT->configureLoggingByContainer($container);
        $this->providerModel = new \ProviderFE();
        $this->balanceService = $container->get('balanceService');
    }

    /**
     * Get user connected exchange leverage for a given symbol.
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function getLeverage(Request $request, array $payload)
    {
        $data = new \stdClass();
        $user = $this->validateCommonConstraints($payload);
        $exchange = $this->resolveUserExchangeConnection($user, $payload['exchangeInternalId']);
        $symbol = filter_var($payload['symbol'], FILTER_SANITIZE_STRING);
        $symbolData = $exchange->findSymbolFormatAgnostic($symbol);

        try {
            // Proceed only when symbol was found in the exchange market data.
            if ($symbolData) {
                $data = $exchange->getLeverage($symbolData['id']);
            }
        } catch (\Exception $e) {
            $this->monolog->sendEntry(
                'WARNING',
                sprintf(
                    "Get leverage request for %s symbol failed: %s",
                    $symbol,
                    $e->getMessage()
                )
            );
        }

        return new JsonResponse($data);
    }

    /**
     * Extract the exchange matching $exchangeInternalId from $user.
     *
     * @param BSONDocument $user
     * @param string $exchangeInternalId
     * @return bool|object
     */
    private function extractExchange(BSONDocument $user, string $exchangeInternalId)
    {
        if (empty($user->exchanges)) {
            return false;
        }

        foreach ($user->exchanges as $exchange) {
            if ($exchange->internalId == $exchangeInternalId) {
                return $exchange;
            }
        }

        return false;
    }
    /**
     * Cancel the given orderId for the given symbol directly from the exchange.
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function cancelOrder(Request $request, array $payload)
    {
        $return = false;
        $user = $this->validateCommonConstraints($payload);
        $symbol = filter_var($payload['symbol'], FILTER_SANITIZE_STRING);
        $exchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);
        $orderId = filter_var($payload['orderId'], FILTER_SANITIZE_STRING);
        if (empty($payload['orderId'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        try {
            if (!empty($payload['providerId'])) {
                $providerId = filter_var($payload['providerId'], FILTER_SANITIZE_STRING);
                $provider = $this->providerModel->getProvider($user->_id, $providerId);
                $profitSharingUser = $this->userModel->getProfitSharingUser();
                if ($provider->userId->__toString() !== $user->_id->__toString()) {
                    $this->monolog->sendEntry('debug', "User {$user->_id->__toString()} is trying to cancel contracts from $providerId");
                    sendHttpResponse(['error' => ['code' => 17]]);
                }
                $exchange = $this->getExchangeFromProfitSharingUser($profitSharingUser, $providerId);
                if (empty($exchangeInternalId)) {
                    sendHttpResponse(['error' => ['code' => 12]]);
                }
                $user = $profitSharingUser;
                $exchangeInternalId = $exchange->internalId;
            }
            $exchange = $this->extractExchange($user, $exchangeInternalId);
            if (!$exchange) {
                sendHttpResponse(['error' => ['code' => 12]]);
            }
            $exchangeAccountType = empty($exchange->exchangeType) ? 'spot' : $exchange->exchangeType;
            $exchangeName = isset($exchange->exchangeName) ? $exchange->exchangeName : $exchange->name;
            $isTestnet = !empty($exchange->isTestnet);
            if (!$this->exchangeCalls->setCurrentExchange($exchangeName, $exchangeAccountType, $isTestnet)) {
                sendHttpResponse(['error' => ['code' => 1040]]);
            }
            $symbolData = $this->exchangeCalls->findSymbolFormatAgnostic($symbol);
            if ($symbolData == null) {
                sendHttpResponse(['error' => ['code' => 44]]);
            }
            $symbol = $symbolData['symbol'];
            $canceledOrder = $this->exchangeCalls->cancelOrderDirectly($orderId, $user->_id, $exchangeInternalId, $symbol);
            if ($canceledOrder instanceof ExchangeOrderCcxt) {
                $return = true;
            }
        } catch (\Exception $e) {
            $this->monolog->sendEntry(
                'WARNING',
                sprintf(
                    "Canceling order failed.",
                    $symbol,
                    $e->getMessage()
                )
            );
        }

        return new JsonResponse($return);
    }

    /**
     * Send an order for the given symbol for the amount and opposite side for reducing a contract.
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function reduceContract(Request $request, array $payload)
    {
        $return = false;
        $user = $this->validateCommonConstraints($payload);
        $symbol = filter_var($payload['symbol'], FILTER_SANITIZE_STRING);
        $exchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);

        try {
            if (!empty($payload['providerId'])) {
                $providerId = filter_var($payload['providerId'], FILTER_SANITIZE_STRING);
                $provider = $this->providerModel->getProvider($user->_id, $providerId);
                $profitSharingUser = $this->userModel->getProfitSharingUser();
                if ($provider->userId->__toString() !== $user->_id->__toString()) {
                    $this->monolog->sendEntry('debug', "User {$user->_id->__toString()} is trying to cancel contracts from $providerId");
                    sendHttpResponse(['error' => ['code' => 17]]);
                }
                $exchange = $this->getExchangeFromProfitSharingUser($profitSharingUser, $providerId);
                if (empty($exchange)) {
                    sendHttpResponse(['error' => ['code' => 12]]);
                }
                $user = $profitSharingUser;
                $exchangeInternalId = $exchange->internalId;
            }
            $exchange = $this->extractExchange($user, $exchangeInternalId);
            if (!$exchange) {
                sendHttpResponse(['error' => ['code' => 12]]);
            }
            $exchangeAccountType = empty($exchange->exchangeType) ? 'spot' : $exchange->exchangeType;
            if ($exchangeAccountType != 'futures') {
                sendHttpResponse(['error' => ['code' => 86]]);
            }
            $exchangeName = isset($exchange->exchangeName) ? $exchange->exchangeName : $exchange->name;
            $isTestnet = !empty($exchange->isTestnet);
            if (!$this->exchangeCalls->setCurrentExchange($exchangeName, $exchangeAccountType, $isTestnet)) {
                sendHttpResponse(['error' => ['code' => 1040]]);
            }
            $symbolData = $this->exchangeCalls->findSymbolFormatAgnostic($symbol);
            if ($symbolData == null) {
                sendHttpResponse(['error' => ['code' => 44]]);
            }
            $symbol = $symbolData['symbol'];

            if (empty($payload['amount'])) {
                sendHttpResponse(['error' => ['code' => 21]]);
            } else {
                $amount = (float) filter_var($payload['amount'], FILTER_SANITIZE_STRING);
            }

            if ($amount > 0) {
                $side = 'sell';
            } else {
                $side = 'buy';
                $amount = abs($amount);
            }

            $options['reduceOnly'] = true;
            $options['marginMode'] = 'ignore';

            $order = $this->exchangeCalls->sendOrder(
                $user->_id,
                $exchangeInternalId,
                $symbol,
                'market',
                $side,
                $amount,
                null,
                $options,
                true
            );
            if (!is_object($order) && !empty($order['error'])) {
                sendHttpResponse(['error' => ['code' => 87]]);
                $this->monolog->sendEntry('critical', 'Error reducing contract:', $order);
            } else {
                $return = 'OK';
            }
        } catch (\Exception $e) {
            $this->monolog->sendEntry(
                'WARNING',
                sprintf(
                    "Canceling order failed.",
                    $symbol,
                    $e->getMessage()
                )
            );
        }

        return new JsonResponse($return);
    }

    /**
     * Update user connected exchange leverage for a given symbol.
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function changeLeverage(Request $request, array $payload)
    {
        $data = new \stdClass();
        $user = $this->validateCommonConstraints($payload);
        $exchange = $this->resolveUserExchangeConnection($user, $payload['exchangeInternalId']);
        $symbol = filter_var($payload['symbol'], FILTER_SANITIZE_STRING);

        if (!isset($payload['leverage']) || !is_numeric($payload['leverage'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        $leverage = filter_var($payload['leverage'], FILTER_SANITIZE_NUMBER_INT);
        $symbolData = $exchange->findSymbolFormatAgnostic($symbol);

        try {
            $exchange->market($symbolData['symbol']);
            $data = $exchange->changeLeverage($symbolData['id'], $leverage);
        } catch (\Exception $e) {
            $this->monolog->sendEntry(
                'WARNING',
                sprintf(
                    "Update leverage request for symbol %s failed: %s",
                    $symbol,
                    $e->getMessage()
                )
            );

            sendHttpResponse(['error' => ['code' => 1041]]);
        }

        return new JsonResponse($data);
    }

    /**
     * Get all symbols metadata collection supported by user connected exchange.
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function getExchangeSymbolsMetadata(Request $request, array $payload)
    {
        $token = checkSessionIsActive();
        if (!isset($payload['exchangeInternalId'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        if (!empty($payload['exchange']) && !empty($payload['exchangeType'])) {
            $exchangeName = filter_var($payload['exchange'], FILTER_SANITIZE_STRING);
            $exchangeType = filter_var($payload['exchangeType'], FILTER_SANITIZE_STRING);
        } else {
            $user = $this->userModel->getUser($token);
            $exchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);
            $selectedExchange = $this->userRepository->getConnectedExchangeById($user, $exchangeInternalId);
            $exchangeName = empty($selectedExchange->exchangeName) ? $selectedExchange->name : $selectedExchange->exchangeName;
            $exchangeType = empty($selectedExchange->exchangeType) ? 'spot' : $selectedExchange->exchangeType;
            //$exchange = $this->resolveUserExchangeConnection($user, $exchangeInternalId);
            //$exchangeId = $exchange->getId();
        }
        //Todo: the next if is temporal, until the ZignalyExchangeCodes is able to convert Zignaly to Binance.
        if ('zignaly' === strtolower($exchangeName)) {
            $exchangeName = 'Binance';
        }
        $exchangeId = ZignalyExchangeCodes::getExchangeFromCaseInsensitiveStringWithType($exchangeName, $exchangeType);
        $exchangeData = $this->marketData->getMarkets($exchangeId);
        $markets = [];
        foreach ($exchangeData as $market) {
            if ($market->getIsActive()) {
                $marketArray = $market->asArray();
                // remove base and quote ids
                unset($marketArray['baseId']);
                unset($marketArray['quoteId']);
                $markets[] = $marketArray;
            }
        }

        return new JsonResponse($markets);
    }

    /**
     * Find user connected exchange and resolve the concrete exchange instance.
     *
     * @param \MongoDB\Model\BSONDocument $user User object.
     *
     * @param string $exchangeInternalId User exchange connection internal ID.
     *
     * @return \Zignaly\exchange\BaseExchange
     */
    private function resolveUserExchangeConnection(BSONDocument $user, string $exchangeInternalId)
    {
        $exchangeInternalId = filter_var($exchangeInternalId, FILTER_SANITIZE_STRING);
        $exchangeConnection = $this->userRepository->getConnectedExchangeById($user, $exchangeInternalId);
        if (!$exchangeConnection) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        $userId = $user->_id->__toString();
        if (!isset($exchangeConnection->exchangeType)) {
            $exchangeConnection->exchangeType = 'spot';
        }
        $exchange = $this->exchangeCalls->useConcreteExchangeForConnection($userId, $exchangeConnection, $exchangeConnection->exchangeType);
        if (!$exchange) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        return $exchange;
    }

    /**
     * Validate that request pass expected contraints.
     *
     * @param array $payload Request payload.
     * @param bool $checkSymbol
     *
     * @return BSONDocument Authenticated user.
     */
    private function validateCommonConstraints(array $payload, $checkSymbol = true): BSONDocument
    {$token = checkSessionIsActive();

        if (empty($payload['exchangeInternalId'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        if ($checkSymbol && empty($payload['symbol'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        return $this->userModel->getUser($token);
        $token = checkSessionIsActive();

        if (empty($payload['exchangeInternalId'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        if ($checkSymbol && empty($payload['symbol'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        return $this->userModel->getUser($token);
    }

    /**
     * Get open orders by user connected exchange for a given symbol.
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function getOpenOrders(Request $request, array $payload)
    {
        $user = $this->validateCommonConstraints($payload, false);

        $exchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);
        $exchange = $this->resolveUserExchangeConnection($user, $exchangeInternalId);

        return new JsonResponse($this->prepareOrderData($exchange, $user, $exchangeInternalId, $payload));
    }

    /**
     * Get position from profit sharing user for a given service
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function getOpenOrdersForService(Request $request, array $payload)
    {
        $user = $this->validateCommonConstraints($payload, false);

        $exchange = $this->getProfitSharingExchange($payload, $user);

        $userExchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);

        return new JsonResponse($this->prepareOrderData($exchange, $user, $userExchangeInternalId, $payload));
    }

    /**
     * Fetch and return open orders data attached to the position if exists
     * @param \Zignaly\exchange\BaseExchange $exchange
     * @param BSONDocument $user
     * @param string $exchangeInternalId
     * @param array $payload
     * @return array
     */
    private function prepareOrderData(\Zignaly\exchange\BaseExchange $exchange, BSONDocument $user, string $exchangeInternalId, array $payload)
    {
        $data = [];
        $marketEncoder = BaseMarketEncoder::newInstance(strtolower($exchange->getId()));
        $ccxtSymbol = null;

        $since = null;
        $limit = null;

        if (isset($payload['symbol'])) {
            $symbol = filter_var($payload['symbol'], FILTER_SANITIZE_STRING);
            $symbolData = $exchange->findSymbolFormatAgnostic($symbol);
            $ccxtSymbol = $symbolData['symbol'];
        }

        if (isset($payload['since'])) {
            $since = filter_var($payload['since'], FILTER_VALIDATE_INT);
        }

        if (isset($payload['limit'])) {
            $limit = filter_var($payload['limit'], FILTER_VALIDATE_INT);
        }

        try {
            $orders = $exchange->getOpenOrders($ccxtSymbol, $since, $limit);
            foreach ($orders as $order) {
                try {
                    $zignalySymbol = $marketEncoder->fromCcxt($order->getSymbol());
                } catch (\Exception $ex) {
                    // this could not be posible, but prevents an exception in this process
                    continue;
                }
                $data[] = array(
                    'orderId' => $order->getId(),
                    'positionId' => $this->newPositionCCXT->getPositionFromOrderIdForUserExchange($user->_id, $exchangeInternalId, $order->getId()),
                    'symbol' => $zignalySymbol,
                    'amount' => (float)$order->getAmount(),
                    'price' => (float)$order->getPrice(),
                    'side' => $order->getSide(),
                    'type' => $order->getType(),
                    'timestamp' => $order->getTimestamp(),
                    'datetime' => $order->getStrDateTime(),
                    'status' => $order->getStatus(),
                );
            }
        } catch (\Exception $e) {
            $this->monolog->sendEntry(
                'WARNING',
                sprintf(
                    "Get Open Orders request for symbol %s failed: %s",
                    $symbol,
                    $e->getMessage()
                )
            );

            sendHttpResponse(['error' => ['code' => 1041]]);
        }

        return $data;
    }


    /**
     * Get position by user connected exchange
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function getContracts(Request $request, array $payload)
    {
        $user = $this->validateCommonConstraints($payload, false);

        $exchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);
        $exchange = $this->resolveUserExchangeConnection($user, $exchangeInternalId);

        return new JsonResponse($this->prepareContractData($exchange, $user, $exchangeInternalId));
    }

    /**
     * Get position from profit sharing user for a given service
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function getContractsForService(Request $request, array $payload)
    {
        $user = $this->validateCommonConstraints($payload, false);
        $userExchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);

        $exchange = $this->getProfitSharingExchange($payload, $user);

        return new JsonResponse($this->prepareContractData(
            $exchange,
            $user,
            $userExchangeInternalId,
            $payload['providerId']?? null)
        );
    }

    /**
     * @param array $payload
     * @return JsonResponse
     */
    public function getExchangeAssetsForService(array $payload)
    {
        $token = checkSessionIsActive();

        $user = $this->userModel->getUser($token);

        //Connect profit sharing exchange
        $this->getProfitSharingExchange($payload, $user);

        $profitSharingUser = $this->userModel->getProfitSharingUser();

        $exchange = $this->getExchangeFromProfitSharingUser($profitSharingUser, $payload['providerId']);

        $result = $this->balanceService->getExchangeAssets($exchange, $this->exchangeCalls);

        if (isset($result['error'])) {
            $this->monolog->sendEntry('error', $result['error'], [$result['extended']]);
            throw new \RuntimeException($result['error']);
        }

        return new JsonResponse($result);
    }

    /**
     * Fetch and return contract data attached to the position if exists
     * @param \Zignaly\exchange\BaseExchange $exchange
     * @param BSONDocument $user
     * @param string $exchangeInternalId
     * @param string $providerId
     * @return array
     */
    private function prepareContractData(\Zignaly\exchange\BaseExchange $exchange, BSONDocument $user, string $exchangeInternalId, string $providerId = null)
    {
        $data = [];
        try {
            $marketEncoder = BaseMarketEncoder::newInstance(strtolower($exchange->getId()));
            $positions = $exchange->getPosition();
            foreach ($positions as $position) {
                try {
                    $symbol = $marketEncoder->fromCcxt($position->getSymbol());
                } catch (\Exception $ex) {
                    continue;
                }
                if (empty($symbol)) {
                    continue;
                }
                if (empty((float)$position->getAmount())) {
                    continue;
                }
                // BitMEX does not provide side in getPosition endpoint (maybe if position.ammount > 0 => side='buy'???)
                $positionSide = $position->getSide() == null ? "both" : $position->getSide();
                $data[] = array(
                    'position' => $this->newPositionCCXT->getPositionFromContractInfoForUserExchange($user->_id, $exchangeInternalId, $symbol, $positionSide, $providerId),
                    'amount' => (float)$position->getAmount(),
                    'entryprice' => (float)$position->getEntryPrice(),
                    'leverage' => (int)$position->getLeverage(),
                    'liquidationprice' => (float)$position->getLiquidationPrice(),
                    'margin' => $position->getMargin(),
                    'markprice' => (float)$position->getMarkPrice(),
                    'side' => $position->getSide(),
                    'symbol' => $symbol,
                );
            }
        } catch (\Exception $e) {
            $this->monolog->sendEntry(
                'WARNING',
                sprintf(
                    "Get position request %s failed: %s",
                    $e->getMessage()
                )
            );
            sendHttpResponse(['error' => ['code' => 1041]]);
        }
        return $data;
    }


    /**
     * Flush the current contracts from a testnet account in Lando environment.
     *
     * @param Request $request
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function flushTestNetAccount(Request $request, array $payload)
    {
        $data = new \stdClass();
        $user = $this->validateCommonConstraints($payload, false);

        $exchangeInternalId = filter_var($payload['exchangeInternalId'], FILTER_SANITIZE_STRING);
        try {
            if (getenv('LANDO') !== 'ON') {
                sendHttpResponse(['error' => ['code' => 88]]);
            }

            if (empty($user->exchanges)) {
                sendHttpResponse(['error' => ['code' => 88]]);
            }

            foreach ($user->exchanges as $exchange) {
                if ($exchange->internalId == $exchangeInternalId) {
                    if (empty($exchange->isTestnet)) {
                        sendHttpResponse(['error' => ['code' => 88]]);
                    } else {
                        $isTestnet = true;
                        $exchangeName = $exchange->name;
                        $exchangeType = $exchange->exchangeType;
                    }
                }
            }

            if (empty($isTestnet)) {
                sendHttpResponse(['error' => ['code' => 88]]);
            }

            if (!$this->exchangeCalls->setCurrentExchange($exchangeName, $exchangeType, true)) {
                sendHttpResponse(['error' => ['code' => 1040]]);
            }

            $contracts = $this->exchangeCalls->getContracts($user->_id, $exchangeInternalId);
            if (empty($contracts)) {
                sendHttpResponse(['error' => ['code' => 88]]);
            }

            if (isset($contracts['error'])) {
                $this->monolog->sendEntry('error', 'Error retrieving contracts', $contracts);
                sendHttpResponse(['error' => ['code' => 88]]);
            }

            foreach ($contracts as $contract) {
                if ($contract->getAmount() != 0) {
                    $this->monolog->sendEntry('debug', 'Contract: ', $contract->getCcxtDict());
                    $amount = $contract->getAmount();
                    if ($amount > 0) {
                        $side = 'sell';
                    } else {
                        $side = 'buy';
                        $amount = abs($amount);
                    }
                    $options['reduceOnly'] = true;
                    $options['marginMode'] = 'ignore';

                    $order = $this->exchangeCalls->sendOrder(
                        $user->_id,
                        $exchangeInternalId,
                        $contract->getSymbol(),
                        'market',
                        $side,
                        $amount,
                        null,
                        $options,
                        true
                    );

                    if (!is_object($order) && isset($order['error'])) {
                        $this->monolog->sendEntry('error', 'Error reducing contract', $order);
                    }
                }
            }
            $data = 'OK';
        } catch (\Exception $e) {
            $this->monolog->sendEntry(
                'WARNING',
                sprintf(
                    "Get position request %s failed: %s",
                    $e->getMessage()
                )
            );

            sendHttpResponse(['error' => ['code' => 88]]);
        }

        return new JsonResponse($data);
    }
}
