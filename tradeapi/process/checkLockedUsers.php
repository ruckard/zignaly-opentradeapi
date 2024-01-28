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

require_once __DIR__ . '/../loader.php';
global $Monolog, $newUser, $continueLoop;

$process = 'checkLockedUsers';
$Monolog = new Monolog($process);
$Position->configureLogging($Monolog);

$scriptStartTime = time();

$alerts = [];
$minutes = 2;
while ($continueLoop) {
    $Monolog->trackSequence();
    $startTime = time();
    $user = $newUser->getOlderLockedUsersForMoreThan(120, $process);
    if (!isset($user->email)) {
        $Monolog->sendEntry('debug', "No users locked");
        sleep(300);
        continue;
    }

    $Monolog->addExtendedKeys('userId', $user->_id->__toString());
    $Monolog->sendEntry('debug', "User " . $user->_id->__toString() . " locked since " . $user->lockedAt->__toString());


    if ($newUser->unlockUser($user->_id) < 1) {
        $Monolog->sendEntry('error', "User " . $user->_id->__toString() . " failed to unlock.");
    }
}