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

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Zignaly\Process\DIContainer;

class UserSessionController
{
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

    public function __construct()
    {
        $container = DIContainer::getContainer();
        $this->userModel = new \UserFE();
        if (!$container->has('monolog')) {
            $container->set('monolog', new Monolog('UserSessionController'));
        }
        $this->monolog = $container->get('monolog');
    }

    /**
     * Get token expiration data.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getSessionData(Request $request, array $payload)
    {
        $lastUsedAt = false;
        $currentExpirationSeconds = 0;

        if (!empty($payload['token'])) {
            $token = filter_var($payload['token'], FILTER_SANITIZE_STRING);
            $isActive = $this->userModel->checkAndUpdateSession($token);
            if (empty($isActive['error'])) {
                $user = $this->userModel->getUser($token, true);
                if (empty($user['error']) && !empty($user->session)) {
                    $currentExpirationSeconds = $this->userModel->getSessionExpirationTime();
                    foreach ($user->session as $session) {
                        $lastUsedAt = !empty($session->lastUsedAt) && is_object($session->lastUsedAt) ? $session->lastUsedAt->__toString() / 1000 : false;
                    }
                }
            }
        }

        $data = [
            'status' => empty($lastUsedAt) ? 'expired' : 'active',
            'validUntil' => empty($lastUsedAt) ? false : $lastUsedAt + $currentExpirationSeconds,
        ];

        return new JsonResponse($data);
    }

    /**
     * Get user from token.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getUserId(Request $request)
    {
        $data = ['id' => null];

        if (!$request->query->has('secret') || $request->query->get('secret') !== CLOUDFLARE_INTERNAL_SECRET) {
            return new JsonResponse($data);
        }
        if (!$request->query->has('token')) {
            return new JsonResponse($data);
        }

        $token = filter_var($request->query->get('token'), FILTER_SANITIZE_STRING);
        $isActive = $this->userModel->checkAndUpdateSession($token);
        if (empty($isActive['error'])) {
            $user = $this->userModel->getUser($token, true);
            if (empty($user['error']) && !empty($user->session)) {
                $data['id'] = (string)$user->_id;
            }
        }

        return new JsonResponse($data);
    }
}
