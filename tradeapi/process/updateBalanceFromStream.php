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


use \MongoDB\Model\BSONDocument;
use Zignaly\Balance\BalanceService;
use Zignaly\Entity\Query\UserExchangeBalanceQuery;
use Zignaly\Process\DIContainer;

require_once __DIR__ . '/../loader.php';
global $continueLoop;
$processName = 'updateBalanceFromStream';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');
/** @var BalanceService $balanceService */
$balanceService = $container->get('balanceService');
/** @var newUser $newUser */
$newUser = $container->get('newUser.model');
/** @var ExchangeCalls $ExchangeCalls */
$ExchangeCalls = $container->get('exchangeMediator');

$queueName = 'updateBalanceFromStreamQueue';

$userId = $argv['1'] ?? false;
$internalExchangeId = $argv['2'] ?? false;

if (!$ExchangeCalls->setCurrentExchange('binance')) {
    $Monolog->sendEntry('critical', "Not able to connect to the exchange");
    sleep(30);
    exit();
}

do {
    try {
        $Monolog->trackSequence();
        $userData = getUserForUpdatingBalanceFromStream($RedisHandlerZignalyQueue, $Monolog, $newUser, $userId, $internalExchangeId, $queueName);
        if (empty($userData[0]) || empty($userData[1])) {
            continue;
        }


        /** @var BSONDocument $user */
        $user = $userData[0];
        $internalExchangeId = $userData[1];
        $connectedExchange = getConnectedExchange($user, $internalExchangeId);
        if (!$connectedExchange) {
            $Monolog->sendEntry('debug', 'Exchange not found in the user exchanges list.');
            if ($continueLoop) {
                continue;
            }
            exit();
        }
        if (!isset($connectedExchange->subAccountId)) {
            $Monolog->sendEntry('debug', 'No broker sub-account.');
            if ($continueLoop) {
                continue;
            }
            exit();
        }
        $exchangeType = empty($connectedExchange->exchangeType) ? 'spot' : $connectedExchange->exchangeType;
        if ('futures' === $exchangeType) {
            transferBalanceFromSpotToFutures($Monolog, $ExchangeCalls, $user, $connectedExchange);
        }

        $Monolog->sendEntry('debug', 'Updating balance.');
        $balanceService->updateBalance($user, $internalExchangeId);
        $firstDeposit = updateUserWithBalanceUpdate($newUser, $user, $connectedExchange);
        if ($firstDeposit) {
            sendFirstDepositEvent($Monolog, $RedisHandlerZignalyQueue, $user->_id->__toString(), $connectedExchange);
        }
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Unknown error: " . $e->getMessage());
        if (true === stripos($e->getMessage(), 'OAUTH Authentication required') || true === stripos($e->getMessage(), 'Redis server')) {
            sleep(rand(1, 5));
            exit();
        }
    }
} while ($continueLoop);

/**
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param string $userId
 * @param object $connectedExchange
 */
function sendFirstDepositEvent(Monolog $Monolog, RedisHandler $RedisHandlerZignalyQueue, string $userId, object $connectedExchange)
{
    $Monolog->sendEntry('info', "Sending first deposit event.");
    $event = [
        'type' => 'firstDeposit',
        'userId' => $userId,
        'parameters' => [
            'exchangeName' => $connectedExchange->internalName,
            'exchangeInternalId' => $connectedExchange->internalId,
        ],
        'timestamp' => time(),
    ];
    $RedisHandlerZignalyQueue->insertElementInList('eventsQueue', json_encode($event));
}

/**
 * @param newUser $newUser
 * @param BSONDocument $user
 * @param object $connectedExchange
 * @return bool
 */
function updateUserWithBalanceUpdate(newUser $newUser, BSONDocument $user, object $connectedExchange)
{
    $firstDeposit = false;
    $find = [
        '_id' => $user->_id,
        'exchanges.internalId' => $connectedExchange->internalId,
    ];

    $updateUser = [
        'lastUpdatedBalance' => new \MongoDB\BSON\UTCDateTime()
    ];

    if (empty($connectedExchange->firstDeposit)) {
        $updateUser['exchanges.$.firstDeposit'] = true;
        $firstDeposit = true;
    }

    if (empty($connectedExchange->balanceSynced)) {
        $updateUser['exchanges.$.balanceSyncedAt'] = new \MongoDB\BSON\UTCDateTime();
        $updateUser['exchanges.$.balanceSynced'] = true;
    }

    $set = [
        '$set' => $updateUser,
    ];

    $newUser->rawUpdate($find, $set);

    return $firstDeposit;
}

/**
 * @param Monolog $Monolog
 * @param ExchangeCalls $ExchangeCalls
 * @param BSONDocument $user
 * @param object $userExchangeConnection
 * @return bool
 */
function transferBalanceFromSpotToFutures(Monolog $Monolog, ExchangeCalls $ExchangeCalls, BSONDocument $user, object $userExchangeConnection)
{
    $subAccountId = $userExchangeConnection->subAccountId;
    $Monolog->addExtendedKeys('subAccountId', $subAccountId);
    $Monolog->sendEntry('info', "Processing balance check for $subAccountId sub-account.");
    try {
        //$ExchangeCalls->reConnectExchangeWithKeys($user->_id->__toString(), $internalExchangeId);
        $ExchangeCalls->useConcreteExchangeForConnection($user->_id->__toString(), $userExchangeConnection, 'spot');
        $balanceData = $ExchangeCalls->getAllPairsBalance();
    } catch (\Exception $e) {
        $Monolog->sendEntry(
            'error',
            sprintf(
                'Get %s sub-account balance failed with error: %s',
                $subAccountId,
                $e->getMessage()
            )
        );
    }

    if (empty($balanceData)) {
        $Monolog->sendEntry('error', "Empty balance retrieved for {$subAccountId} sub-account.");
        return false;
    }

    $userExchangeBalanceQuery = new UserExchangeBalanceQuery($balanceData);
    $accountType = $userExchangeBalanceQuery->getAccountType();
    if ('SPOT' !==$accountType) {
        $Monolog->sendEntry(
            'error',
            sprintf(
                "Skip balance transfer processing for %s - %s sub-account, only SPOT type is expected.",
                $subAccountId,
                $accountType
            )
        );

        return false;
    }

    $currenciesFreeBalance = $userExchangeBalanceQuery->getFreeByCurrency();
    if (empty($currenciesFreeBalance)) {
        $Monolog->sendEntry(
            'info',
            sprintf(
                "No free balance available to transfer for %s - %s sub-account.",
                $subAccountId,
                $accountType
            )
        );
        return false;
    }

    // Transfer the balance for each currency.
    foreach ($currenciesFreeBalance as $symbol => $amount) {
        $Monolog->sendEntry(
            'info',
            sprintf(
                "Processing %s balance %s amount transfer for %s - %s sub-account.",
                $symbol,
                $amount,
                $subAccountId,
                $accountType
            )
        );

        $ExchangeCalls->balanceTransfer($symbol, $amount, 1);
    }

    return true;
}

/**
 * Return the type of exchange or false if it doesn't find any.
 * @param BSONDocument $user
 * @param string $internalExchangeId
 * @return bool|object
 */
function getConnectedExchange(BSONDocument $user, string $internalExchangeId)
{
    if (empty($user->exchanges)) {
        return false;
    }
    foreach ($user->exchanges as $exchange) {
        if ($exchange->internalId === $internalExchangeId) {
            return $exchange;
        }
    }

    return false;
}

/**
 * @param RabbitMQ $RabbitMQ
 * @param BSONDocument $position
 * @param string $command
 * @param bool|string $error
 * @param bool|array $extraParameters
 */
function sendNotification(RabbitMQ $RabbitMQ, BSONDocument $position, string $command, $error = false, $extraParameters = false)
{
    $parameters = [
        'userId' => $position->user->_id->__toString(),
        'positionId' => $position->_id->__toString(),
        'status' => $position->status,
    ];

    if ($error) {
        $parameters['error'] = $error;
    }

    if ($extraParameters and is_array($extraParameters)) {
        foreach ($extraParameters as $key => $value) {
            $parameters[$key] = $value;
        }
    }

    $message = [
        'command' => $command,
        'chatId' => false,
        'code' => false,
        'parameters' => $parameters
    ];

    $message = json_encode($message, JSON_PRESERVE_ZERO_FRACTION);
    $RabbitMQ->publishMsg('profileNotifications', $message);
}

/**
 * Get the position from a given id or from the set and lock it.
 *
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param Monolog $Monolog
 * @param newUser $newUser
 * @param bool|string $userId
 * @param bool|string $internalExchangeId
 * @param string $queueName
 * @return array
 */
function getUserForUpdatingBalanceFromStream(
    RedisHandler $RedisHandlerZignalyQueue,
    Monolog $Monolog,
    newUser $newUser,
    string $userId,
    string $internalExchangeId,
    string $queueName
) {
    global $continueLoop;

    if (!$userId) {
        $popMember = $RedisHandlerZignalyQueue->popFromSetOrBlock($queueName);
        if (!empty($popMember)) {
            $userData = json_decode($popMember[1], true);
            $Monolog->sendEntry('debug', 'Received:', $userData);
            $userId = $userData['user'];
            $internalExchangeId = $userData['exch'];
        } else {
            return [false, false];
        }
    } else {
        $Monolog->sendEntry('debug', "Received: $userId and $internalExchangeId manually.");
        $continueLoop = false;
    }

    if ($userId) {
        $Monolog->addExtendedKeys('userId', $userId);
        $Monolog->addExtendedKeys('internalExchangeId', $internalExchangeId);
        $user = $newUser->getUser($userId);
    }

    return !empty($user) ? [$user, $internalExchangeId] : [false, false];
}
