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


use MongoDB\Model\BSONDocument;
use Zignaly\Balance\BalanceService;
use Zignaly\Process\DIContainer;

$secondaryDBNode = true;
$excludeRabbit = true;
$retryServerSelection = true;
$socketTimeOut = true;
require_once __DIR__ . '/../loader.php';
global $newUser, $continueLoop;

$processName = 'fetchBalance';
$container = DIContainer::getContainer();
$monolog = new Monolog($processName);
$container->set('monolog', $monolog);

/** @var BalanceService $balanceService */
$balanceService = $container->get('balanceService');

$userId = $argv['1'] ?? false;
$internalExchangeId = $argv['2'] ?? false;

$scriptStartTime = time();

do {
    $monolog->trackSequence();
    /** @var BSONDocument $user */
    $user = $newUser->getLastBalanceUpdatedUser($userId);
    //Todo: Check if it's an active user.
    if ($user) {
        $monolog->addExtendedKeys('userId', $user->_id->__toString());
        fetchAndStoreData($monolog, $user, $balanceService, $internalExchangeId);
        $newUser->unlockUser($user->_id, 'lastUpdatedBalance');
    }
} while ($continueLoop && !$userId);

/**
 * Fetches balance data from each exchange and store in the dailyBalance collection.
 *
 * @param Monolog $monolog
 * @param BSONDocument $user
 * @param BalanceService $balanceService
 * @param bool|string $internalExchangeId
 * @return bool
 */
function fetchAndStoreData(
    Monolog $monolog,
    BSONDocument $user,
    BalanceService  $balanceService,
    $internalExchangeId
) {
    if (4 === $user->status || empty($user->exchanges)) {
        return false;
    }

    foreach ($user->exchanges as $exchange) {
        try {
            if (empty($exchange->subAccountId)) {
                continue;
            }

            if (empty($exchange->secret)) {
                continue;
            }

            if (!isset($exchange->name) || (isset($exchange->areKeysValid) && !$exchange->areKeysValid)) {
                continue;
            }

            if ($internalExchangeId && $internalExchangeId !== $exchange->internalId) {
                continue;
            }

            $balanceService->updateBalance($user, $exchange->internalId);
        } catch (\Throwable $e) {
            $monolog->sendEntry('error', "{$exchange->name}: {$e->getMessage()}");

            continue;
        }
    }

    return true;
}
