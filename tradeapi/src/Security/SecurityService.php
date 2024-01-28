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


namespace Zignaly\Security;

use CodeErrors;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;
use TOTP;

/**
 * Class SecurityService
 * @package Zignaly\Security
 */
class SecurityService
{
    /**
     * @var \UserFE
     */
    private $userModel;

    /**
     * SecurityService constructor.
     * @param \UserFE $userModel
     */
    public function __construct(\UserFE $userModel)
    {
        $this->userModel = $userModel;
    }

    /**
     * @param BSONDocument $user
     * @param string $reason
     *
     * @return string
     */
    public function generateRecoveryToken(BSONDocument $user, string $reason): string
    {
        $recoveryData = [
                'recoveryHash' => md5(''),
                'recoveryRequestedAt' => new UTCDateTime(),
                'recoveryVisited' => false,
                'recoveryReason' => $reason
        ];

        /** @var array $result */
        $result = $this->userModel->updateUser($user->_id, $recoveryData);

        if (\is_array($result)) {
            throw new SecurityException('Error generating recovery data', $result['error']['code']);
        }

        return $recoveryData['recoveryHash'];
    }

    /**
     * @param $token
     * @param $reason
     * @param bool|null $checkVisited
     * @return BSONDocument
     */
    public function checkRecoveryToken($token, $reason, ?bool $checkVisited = null): BSONDocument
    {
        /** @var BSONDocument $user */
        $user = $this->userModel->getUserByFind(['recoveryHash' => $token, 'recoveryReason' => $reason]);

        if (!($user instanceof BSONDocument)) {
            throw new SecurityException('User not found or token expired', CodeErrors::UserNotFoundOrTokenExpired);
        }

        if ($checkVisited ?? false) {
            if (isset($user->recoveryVisited) && $user->recoveryVisited) {
                throw new SecurityException('Recovery url already visited', CodeErrors::UrlVisited);
            }

            /** @var array $result */
            $result = $this->userModel->updateUser($user->_id, ['recoveryVisited' => true]);

            if (\is_array($result)) {
                throw new SecurityException('Error updating user data', $result['error']['code']);
            }
        }

        $requestedAt = $user->recoveryRequestedAt->__toString() / 1000;
        $limitTime = $requestedAt + 15 * 60;
        if (time() > $limitTime) {
            throw new SecurityException('Recovery token expired', CodeErrors::TokenExpired);
        }

        return $user;
    }

    /**
     * check session is active
     *
     * @param array $payload
     * @param bool|null $check2FA
     *
     * @return string
     */
    public function checkSessionIsActive(array $payload, ?bool $check2FA = null): string
    {
        if (!isset($payload['token'])) {
            throw new SecurityException('Token not provided', CodeErrors::TokenNotProvided);
        }

        $token = filter_var($payload['token'], FILTER_SANITIZE_STRING);

        /** @var array $result */
        $result = $this->userModel->checkAndUpdateSession($token, $check2FA ?? true);

        if (\is_array($result)) {
            throw new SecurityException('Session expired', $result['error']['code']);
        }

        return $token;
    }

    /**
     * @param BSONDocument $user
     * @param array | null $additionalSettings
     */
    public function resetRecoveryData(BSONDocument $user, ?array $additionalSettings = null): void
    {
        $set = array_merge(
            [
                'recoveryHash' => false,
                'recoveryRequestedAt' => false,
                'recoveryVisited' => false,
                'recoveryReason' => false
            ],
            $additionalSettings ?? []
        );

        /** @var array $result */
        $result = $this->userModel->updateUser($user->_id, $set);

        if (\is_array($result)) {
            throw new SecurityException('Error resetting recovery data', $result['error']['code']);
        }
    }

    /**
     * @param BSONDocument $user
     * @param string $token
     * @param string $code
     */
    public function verify2FA(BSONDocument $user, string $token, string $code): void
    {
        $totp = new TOTP($user, $token);
        if (!$totp->verifyCode($code)) {
            throw new SecurityException('2FA wrong code', CodeErrors::WrongCode);
        }
    }
}
