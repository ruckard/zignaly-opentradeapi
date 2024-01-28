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
use ImageKit\ImageKit;

class ImageKitController
{
    /**
     * Monolog service.
     *
     * @var \Monolog
     */
    private $monolog;

    /**
     * User model.
     *
     * @var \UserFE
     */
    private $userModel;

    public function __construct()
    {
        $container = DIContainer::getContainer();
        $this->monolog = $container->get('monolog');
        $this->userModel = new \UserFE();
    }

    /**
     * Provides exchange name IDs list supported by Zignaly.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getToken(Request $request, array $payload): JsonResponse
    {
        $this->validateConstraints($payload);

        $imageKit = new ImageKit(
            IMAGEKIT_PUB,
            IMAGEKIT_PRI,
            "https://ik.imagekit.io/zignaly"
        );

        return new JsonResponse($imageKit->getAuthenticationParameters($token = "", $expire = 0));
    }

    /**
     * Validate that request pass expected contraints.
     *
     * @param array $payload Request payload.
     *
     * @return BSONDocument Authenticated user.
     */
    private function validateConstraints(array $payload): BSONDocument
    {
        $token = checkSessionIsActive();

        $user = $this->userModel->getUser($token);

        return $user;
    }
}