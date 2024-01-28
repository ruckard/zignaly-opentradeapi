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
use Zignaly\Process\DIContainer;

$secondaryDBNode = true;
$retryServerSelection = true;
$socketTimeOut = true;

require_once dirname(__FILE__) . '/../loader.php';
global $continueLoop, $Security;

$processName = 'newEventsDispatcher';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');
/** @var newUser $newUser */
$newUser = $container->get('newUser.model');
$HistoryDB = new HistoryDB(300000, true);

$user_id = $argv['1'];

print_r(composeSignupEvent($HistoryDB, $newUser, $user_id));


/**
 * @param newUser $newUser
 * @param string $user_id
 * @return array
 * @throws Exception
 */
function composeSignupEvent(HistoryDB $HistoryDB, newUser $newUser, string $user_id): array
{
    $user = $newUser->getUser($user_id);
    if (empty($user->createdAt)) {
        throw new Exception("User not found from id: $user_id");
    }

    $email = empty($user->email) ? null : $user->email;
    $evm_address = empty($user->evm_address) ? null : $user->evm_address;

    list($invited_by_id, $ref_code, $invited_tag) = composeUserReferralData($user);
    list($from_url, $ab_version, $agent, $ip_address) = composeVisitData($HistoryDB, $user_id);
    return [
        'user_id' => $user_id,
        'from_url' => $from_url,
        'ab_version' => $ab_version,
        'agent' => $agent,
        'ip_address' => $ip_address,
        'invited_by_id' => $invited_by_id,
        'ref_code' => $ref_code,
        'invited_tag' => $invited_tag,
        'email' => $email,
        'evm_address' => $evm_address,
    ];
}

/**
 * @param HistoryDB $HistoryDB
 * @param string $user_id
 * @return array
 */
function composeVisitData(HistoryDB $HistoryDB, string $user_id): array
{
    $track_ids = $HistoryDB->getTrackIds($user_id);
    $first_visit = $HistoryDB->findFirstVisit($track_ids);

    $from_url = empty($first_visit->urlReferer) ? null : $first_visit->urlReferer;
    $ab_version = null; //ToDo: We need to extract this from the url.
    $agent = empty($first_visit->userAgent) ? null : $first_visit->userAgent;
    $ip_address = empty($first_visit->ip) ? null : $first_visit->ip;;

    return [$from_url, $ab_version, $agent, $ip_address];
}

/**
 * @param BSONDocument $user
 * @return array
 */
function composeUserReferralData(BSONDocument $user): array
{
    if (empty($user->referringUserId) || empty($user->referringCode)) {
        return [null, null, null];
    }

    $sub_track = empty($user->subtrack) ? null : $user->subtrack;

    return [$user->referringUserId, $user->referringCode, $sub_track];
}