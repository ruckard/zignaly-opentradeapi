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

/**
 * Class FollowersController
 * @package Zignaly\Controller
 */
class FollowersController
{
    /**
     * Monolog service.
     *
     * @var \Monolog
     */
    private $monolog;

    /**
     * @var string
     */
    private $providerId;

    /**
     * ProviderFE model.
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
     * FollowersController constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        $container = DIContainer::getContainer();
        if (!$container->has('monolog')) {
            $container->set('monolog', new \Monolog('FollowersController'));
        }
        $this->monolog = $container->get('monolog');
        $this->userModel = new \UserFE();
        $this->providerModel = new \ProviderFE();
    }


    /**
     * Generate the cumulative growth of followers for a given signal-provider/copy-trader.
     *
     * @param $payload
     * @return JsonResponse
     */
    public function getFollowersChartForProvider($payload)
    {
        $this->validateCommonConstraints($payload);

        $followersChart = [];

        $followersPerDay = $this->userModel->getFollowersPerProviderByDay($this->providerId);

        if (!empty($followersPerDay)) {
            $totalFollowers = 0;
            foreach ($followersPerDay as $day) {
                $totalFollowers += $day->followers;
                $followersChart[] = [
                    'date' => $day->_id,
                    'followers' => $day->followers,
                    'totalFollowers' => $totalFollowers,
                ];
            }
        }

        return new JsonResponse($followersChart);
    }

    /**
     * @param array $payload
     * @return JsonResponse
     */
    public function getFollowersForProvider(array $payload): JsonResponse
    {
        $user = $this->validateCommonConstraints($payload);

        $provider = $this->providerModel->getProvider($user->_id, $this->providerId);
        if (isset($provider['error'])) {
            sendHttpResponse($provider);
        }

        if ($provider->userId->__toString() !== $user->_id->__toString()) {
            $this->monolog->sendEntry(
                'debug',
                "User {$user->_id->__toString()} is trying to get the followers from {$this->providerId}"
            );
            sendHttpResponse(['error' => ['code' => 17]]);
        }

        return new JsonResponse(['followers' => $this->userModel->getFollowersForProvider($this->providerId)]);
    }

    /**
     * Validate that request passes expected constraints.
     *
     * @param array $payload Request payload.
     *
     * @return BSONDocument Authenticated user.
     */
    private function validateCommonConstraints(array $payload): BSONDocument
    {
        $token = checkSessionIsActive();

        if (empty($payload['providerId'])) {
            sendHttpResponse(['error' => ['code' => 30]]);
        }

        $this->providerId = filter_var($payload['providerId'], FILTER_SANITIZE_STRING);

        return $this->userModel->getUser($token);
    }
}
