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
global $CheckOrders, $Monolog, $newPositionCCXT, $continueLoop;

$process = 'checkLockedPositions';
$Monolog = new Monolog($process);
$newPositionCCXT->configureLogging($Monolog);

$scriptStartTime = time();

$alerts = [];
$minutes = 2;
do {
    $Monolog->trackSequence();
    $startTime = time();
    $positions = $newPositionCCXT->getLockedPositionsForLongTime($minutes);
    foreach ($positions as $position) {
        $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());
        $positionId = $position->_id->__toString();
        if (!isset($alerts[$positionId])) {
            $alerts[$positionId] = time();
            $Monolog->sendEntry('debug', "Locked for more than $minutes minutes.");
        }

        $lockedAt = $position->lockedAt->__toString() / 1000;
        $tenMinAgo = time() - 600;
        if ($tenMinAgo > $lockedAt) {
            $lockedFrom = isset($position->lockedFrom) ? $position->lockedFrom : 'Unknown';
            $Monolog->sendEntry('error', "Unlock  because it has been locked more than 10m. Time: "
                . time() . " Locked at: $lockedAt, 10 min ago: $tenMinAgo. By " . $position->lockedBy . ", from $lockedFrom");
            $modifications = $newPositionCCXT->unlockPosition($positionId);
            //$Monolog->sendEntry('debug', "Unlocking: $modifications");
        }
    }

    $alerts = clearAlerts($alerts);
    if (time() - $startTime < 30)
        sleep(10);
} while ($continueLoop);

function clearAlerts($alerts)
{
    $timeLimit = time() - 30 * 60;
    foreach ($alerts as $key => $value) {
        if ($value < $timeLimit)
            unset($alerts[$key]);
    }

    return $alerts;
}