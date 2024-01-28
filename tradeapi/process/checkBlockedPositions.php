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


use Zignaly\Process\DIContainer;

require_once __DIR__ . '/../loader.php';
global $newPositionCCXT, $continueLoop;

$process = 'checkBlockedPositions';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($process));
$Monolog = $container->get('monolog');
$newPositionCCXT->configureLoggingByContainer($container);
$scriptStartTime = time();

$minutes = 10;
do {
    $Monolog->trackSequence();
    $startTime = time();
    $positions = $newPositionCCXT->getBlockedPositions($minutes);
    foreach ($positions as $position) {
        $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());
        $positionId = $position->_id->__toString();
        $blockedAt = $position->lastUpdatingAt->__toString() / 1000;
        $nMinAgo = time() - $minutes * 60;
        if ($nMinAgo > $blockedAt) {
            $Monolog->sendEntry('debug', "Unblock  because it has been locked more than 10m. Time: "
                . time() . " Blocked at: $blockedAt, $minutes min ago: $nMinAgo");
            $newPositionCCXT->setPosition($position->_id, ['updating' => false], false);
        }
    }
    
    if (time() - $startTime < 30) {
        sleep(10);
    }
} while ($continueLoop);
