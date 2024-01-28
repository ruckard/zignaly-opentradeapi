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

use CodeErrors;
use MongoDB\Model\BSONDocument;
use SendGridMailer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Zignaly\Process\DIContainer;
use Zignaly\Security\SecurityException;
use Zignaly\Security\SecurityService;
use MongoDB\BSON\UTCDateTime;

/**
 * Class UserController
 * @package Zignaly\Controller
 */
class UserController
{
    /**
     * User model.
     *
     * @var \UserFE
     */
    private $userModel;

    /**
     * Mailer
     *
     * @var SendGridMailer
     */
    private $sendGridMailer;

    /**
     * @var SecurityService
     */
    private $securityService;

    /**
     * UserController constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        $container = DIContainer::getContainer();
        $this->userModel = new \UserFE();
        $this->sendGridMailer = new SendGridMailer();
        $this->securityService = $container->get('securityService');
    }

    /**
     * Update user.
     *
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function updateUserAction(array $payload): JsonResponse
    {
        $user = $this->validateCommonConstraints($payload);

        $set = [
            'imageUrl' => filter_var($payload['imageUrl'] ?? '', FILTER_SANITIZE_STRING),
            'userName' => filter_var($payload['userName'] ?? '', FILTER_SANITIZE_STRING)
        ];

        /** @var array $data */
        $data = $this->userModel->updateUser($user->_id, $set);

        if (isset($data['error'])) {
            sendHttpResponse($data);
        }

        return new JsonResponse($data);
    }

    /**
     * Disable 2FA request
     *
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function disable2FARequestAction(array $payload): JsonResponse
    {
        $user = $this->validateCommonConstraints($payload, false);

        if ($user) {
            try {
                $recoveryToken = $this->securityService->generateRecoveryToken($user, '2FA');
                $this->sendGridMailer->sendDisable2FAMail($user, $recoveryToken);
            } catch (\Throwable $exception) {
                //Maybe log this exception or throw a new one to response an error
            }
        }

        return new JsonResponse('OK');
    }

    /**
     * @param array $payload
     * @return JsonResponse
     */
    public function disable2FAVisitAction(array $payload): JsonResponse
    {
        $this->markAsVisited($payload, '2FA');

        return new JsonResponse(true);
    }


    /**
     * Disable 2FA
     *
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function disable2FAConfirmAction(array $payload): JsonResponse
    {
        $apiKey = isset($payload['apiKey']) ? filter_var($payload['apiKey'], FILTER_SANITIZE_STRING) : false;

        if (empty($apiKey)) {
            sendHttpResponse(['error' => ['code' => CodeErrors::ParamNotValid]]);
        }

        $token = $this->getRecoveryToken($payload);

        try {
            $user = $this->securityService->checkRecoveryToken($token, '2FA');

            $security = new \Security();
            foreach ($user->exchanges as $exchange) {
                $exchangeApiKey = $security->decrypt($exchange->key);
                if ($exchangeApiKey === $apiKey) {
                    $this->securityService->resetRecoveryData($user, ['enableTOTP' => false]);
                    return new JsonResponse(true);
                }
            }
        } catch (SecurityException $exception) {
            //FIXME: Use Symfony exception handling to response an error
            sendHttpResponse(['error' => ['code' => $exception->getCode()]]);
        }

        sendHttpResponse(['error' => ['code' => CodeErrors::ParamNotValid]]);
    }

    /**
     * Reset email request
     *
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function resetEmailRequestAction(array $payload): JsonResponse
    {
        $user = $this->validateCommonConstraints($payload, false);

        if ($user) {
            try {
                if ($user->enableTOTP) {
                    if (!isset($payload['code'])) {
                        sendHttpResponse(['error' => ['code' => 31]]);
                    }

                    $code = filter_var($payload['code'], FILTER_SANITIZE_STRING);
                    $token = filter_var($payload['token'], FILTER_SANITIZE_STRING);
                    try {
                        $this->securityService->verify2FA($user, $token, $code);
                    } catch (SecurityException $exception) {
                        sendHttpResponse(['error' => ['code' => $exception->getCode()]]);
                    }
                }
                $recoveryToken = $this->securityService->generateRecoveryToken($user, 'resetEmail');
                $this->sendGridMailer->sendResetEmailMail($user, $recoveryToken);
            } catch (\Throwable $exception) {
                sendHttpResponse(['error' => ['code' => 31]]);
            }
        }

        return new JsonResponse('OK');
    }

    /**
     * @param array $payload
     * @return JsonResponse
     */
    public function resetEmailVisitAction(array $payload): JsonResponse
    {
        $this->markAsVisited($payload, 'resetEmail');

        return new JsonResponse(true);
    }

    /**
     * @param array $payload
     * @return JsonResponse
     */
    public function resetEmailConfirmAction(array $payload): JsonResponse
    {
        $token = $this->getRecoveryToken($payload);

        $newEmail = $payload['email'] ?? '';

        if (empty($newEmail)) {
            sendHttpResponse(['error' => ['code' => CodeErrors::FieldEmailMandatory]]);
        }

        if ($newEmail !== filter_var($newEmail, FILTER_SANITIZE_EMAIL)) {
            sendHttpResponse(['error' => ['code' => CodeErrors::FieldEmailInvalid]]);
        }

        try {
            $user = $this->securityService->checkRecoveryToken($token, 'resetEmail');

            if ($this->userModel->checkIfMailExists($newEmail)) {
                sendHttpResponse(['error' => ['code' => CodeErrors::EmailExistsInOurDb]]);
            }

            $this->securityService->resetRecoveryData($user, ['email' => $newEmail]);
            $user->email = $newEmail;
            $this->sendGridMailer->sendResetEmailNotification($user);
        } catch (SecurityException $exception) {
            //FIXME: Use Symfony exception handling to response an error
            sendHttpResponse(['error' => ['code' => $exception->getCode()]]);
        }

        return new JsonResponse(true);
    }

    /**
     * Delete account request
     *
     * @param array $payload Request POST payload.
     *
     * @return JsonResponse
     */
    public function deleteAccountRequestAction(array $payload): JsonResponse
    {
        $user = $this->validateCommonConstraints($payload, false);

        if ($user) {
            try {
                if ($user->enableTOTP) {
                    if (!isset($payload['code'])) {
                        sendHttpResponse(['error' => ['code' => 31]]);
                    }

                    $code = filter_var($payload['code'], FILTER_SANITIZE_STRING);
                    $token = filter_var($payload['token'], FILTER_SANITIZE_STRING);
                    try {
                        $this->securityService->verify2FA($user, $token, $code);
                    } catch (SecurityException $exception) {
                        sendHttpResponse(['error' => ['code' => $exception->getCode()]]);
                    }
                }
                // check user has removed all exchanges
                $this->checkEmptyUserExchanges($user);

                $recoveryToken = $this->securityService->generateRecoveryToken($user, 'deleteAccount');
                $this->sendGridMailer->sendDeleteAccountMail($user, $recoveryToken);
            } catch (\Throwable $exception) {
                sendHttpResponse(['error' => ['code' => 31]]);
            }
        }

        return new JsonResponse('OK');
    }

    /**
     * @param array $payload
     * @return JsonResponse
     */
    public function deleteAccountVisitAction(array $payload): JsonResponse
    {
        $this->markAsVisited($payload, 'deleteAccount');

        return new JsonResponse(true);
    }

    /**
     * @param array $payload
     * @return JsonResponse
     */
    public function deleteAccountConfirmAction(array $payload): JsonResponse
    {
        $token = $this->getRecoveryToken($payload);

        $reason = isset($payload['reason'])
            ? filter_var($payload['reason'], FILTER_SANITIZE_STRING)
            : '';

        try {
            $user = $this->securityService->checkRecoveryToken($token, 'deleteAccount');

            // check user has removed all exchanges
            $this->checkEmptyUserExchanges($user);

            $this->securityService->resetRecoveryData(
                $user,
                [
                    'email' => $user->email . "-DELETED",
                    'deleted' => true,
                    'deletedAt' => new UTCDateTime(),
                    'deletedReason' => $reason,
                    'exchanges' => false,
                    // if exchange is empty this entire update does not work
                    //'exchanges.$[].key' => false,
                    //'exchanges.$[].secret' => false,
                    //'exchanges.$[].password' => false,
                    'TOTPSecret' => false,
                    'notifications' => [],
                    'session' => []
                ]
            );

            $this->sendGridMailer->sendDeleteAccountNotification($user);
        } catch (SecurityException $exception) {
            //FIXME: Use Symfony exception handling to response an error
            sendHttpResponse(['error' => ['code' => $exception->getCode()]]);
        }

        return new JsonResponse(true);
    }

    /**
     * Validate that request pass expected constraints.
     *
     * @param array $payload Request payload.
     * @param bool $check2FA
     *
     * @return BSONDocument Authenticated user.
     */
    private function validateCommonConstraints(array $payload, ?bool $check2FA = null): BSONDocument
    {
        $token = $this->securityService->checkSessionIsActive($payload, $check2FA ?? true);

        return $this->userModel->getUser($token);
    }

    /**
     * @param array $payload
     * @return string
     */
    private function getRecoveryToken(array $payload): string
    {
        return filter_var($payload['token'] ?? '', FILTER_SANITIZE_STRING);
    }

    /**
     * @param array $payload
     * @param string $reason
     */
    private function markAsVisited(array $payload, string $reason): void
    {
        $token = $this->getRecoveryToken($payload);

        try {
            $this->securityService->checkRecoveryToken($token, $reason, true);
        } catch (SecurityException $exception) {
            //FIXME: Use Symfony exception handling to response an error
            sendHttpResponse(['error' => ['code' => $exception->getCode()]]);
        }
    }

    private function checkEmptyUserExchanges($user)
    {
        if (isset($user->exchanges) && (count($user->exchanges) !== 0)) {
            sendHttpResponse(['error' => ['code' => CodeErrors::UserDeletionNotAllowedAlreadyHasExchanges]]);
        }
    }
}
