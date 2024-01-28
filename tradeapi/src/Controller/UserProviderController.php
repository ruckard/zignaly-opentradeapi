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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Zignaly\Process\DIContainer;

class UserProviderController
{
    /**
     * @var $internalExchangeId
     */
    private $internalExchangeId;

    /**
     * @var BSONDocument
     */
    private $user;

    /**
     * @var BSONDocument
     */
    private $provider;

    /**
     * Provider model.
     *
     * @var \ProviderFE
     */
    private $providerModel;

    /**
     * User model.
     *
     * @var \UserFE
     */
    private $userModel;

    /**
     * Monolog service.
     *
     * @var \Monolog
     */
    private $monolog;

    /**
     * RedisHandler service
     *
     * @var \RedisHandler
     */
    private $RedisHandlerZignalyQueue;

    public function __construct()
    {
        $container = DIContainer::getContainer();
        $this->providerModel = new \ProviderFE();
        $this->userModel = new \UserFE();
        if (!$container->has('monolog')) {
            $container->set('monolog', new Monolog('UserController'));
        }
        $this->monolog = $container->get('monolog');
        $this->RedisHandlerZignalyQueue = $container->get('redis.queue');
    }

    /**
     * Update user.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function disconnectProfitSharingService(Request $request, array $payload)
    {
        $this->validateCommonConstraints($payload);

        if (empty($payload['disconnectionType'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }
        $disconnectionType = strtolower(filter_var($payload['disconnectionType'], FILTER_SANITIZE_STRING));

        $providerId = $this->provider->_id->__toString();

        if (empty($this->user->provider->$providerId)) {
            sendHttpResponse(['error' => ['code' => 97]]);
        }

        foreach ($this->user->provider->$providerId->exchangeInternalIds as $exchangesConnected) {
            if ($exchangesConnected->internalId == $this->internalExchangeId) {
                if (!empty($exchangesConnected->disconnected)) {
                    sendHttpResponse(['error' => ['code' => 97]]);
                }
                if (!empty($exchangesConnected->disconnecting)) {
                    sendHttpResponse(['error' => ['code' => 98]]);
                }

                $set = [
                    'provider.' . $providerId . '.exchangeInternalIds.$.disconnecting' => true,
                    'provider.' . $providerId . '.exchangeInternalIds.$.disconnectionType' => $disconnectionType,
                ];

                if ($this->userModel->updateUserProfitSharingService($this->user->_id, $providerId, $this->internalExchangeId, $set) == 1) {
                    $message = json_encode([
                        'userId' => $this->user->_id->__toString(),
                        'internalId' => $this->internalExchangeId,
                        'providerId' => $providerId,
                    ], JSON_PRESERVE_ZERO_FRACTION);
                    $this->RedisHandlerZignalyQueue->addSortedSet('profitSharingDisconnectionQueue', time(), $message, true);
                    return new JsonResponse('OK');
                }
            }
        }

        sendHttpResponse(['error' => ['code' => 97]]);
    }

    /**
     * Update user.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function cancelDisconnecting(Request $request, array $payload)
    {
        $this->validateCommonConstraints($payload);

        $providerId = $this->provider->_id->__toString();

        if (empty($this->user->provider->$providerId)) {
            sendHttpResponse(['error' => ['code' => 97]]);
        }

        /*if (!empty($this->user->provider->$providerId->disable)) {
            sendHttpResponse(['error' => ['code' => 97]]);
        }*/

        foreach ($this->user->provider->$providerId->exchangeInternalIds as $exchangesConnected) {
            if ($exchangesConnected->internalId == $this->internalExchangeId) {
                if (!empty($exchangesConnected->disconnected)) {
                    sendHttpResponse(['error' => ['code' => 97]]);
                }
                if (empty($exchangesConnected->disconnecting)) {
                    return new JsonResponse('OK');
                }

                $set = [
                    'provider.' . $providerId . '.exchangeInternalIds.$.disconnecting' => false,
                    'provider.' . $providerId . '.exchangeInternalIds.$.disconnectionType' => false,
                ];

                if ($this->userModel->updateUserProfitSharingService($this->user->_id, $providerId, $this->internalExchangeId, $set) == 1) {
                    return new JsonResponse('OK');
                }
            }
        }

        sendHttpResponse(['error' => ['code' => 97]]);
    }

    /**
     * Validate that request pass expected constraints.
     *
     * @param array $payload
     * @return bool
     */
    private function validateCommonConstraints(array $payload)
    {
        $token = checkSessionIsActive();
        $user = $this->userModel->getUser($token);

        if (!empty($user['error'])) {
            sendHttpResponse($user);
        } else {
            $this->user = $user;
        }

        $providerId = isset($payload['providerId']) ? filter_var($payload['providerId'], FILTER_SANITIZE_STRING) : false;
        if (empty($providerId)) {
            sendHttpResponse(['error' => ['code' => 30]]);
        }

        $provider = $this->providerModel->getProvider($user->_id, $providerId);

        if (isset($provider['error'])) {
            sendHttpResponse($provider);
        } else {
            $this->provider = $provider;
        }

        if (empty($payload['internalExchangeId'])) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }
        $this->internalExchangeId = filter_var($payload['internalExchangeId'], FILTER_SANITIZE_STRING);

        return true;
    }
}
