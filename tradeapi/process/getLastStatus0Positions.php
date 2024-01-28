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

global $mongoDBLink;

$container = DIContainer::getContainer();
$container->set('monolog', new Monolog('getLastStatus0Positions'));
$notification = $container->get('Notification');

$pipeline = [
    ['$match'  => [
        'closed' => false,
        'status' => 0,
    ]],
    ['$match' => [
        '$and' => [
        ['createdAt' => ['$lt' => new MongoDB\BSON\UTCDateTime(time() * 1000 - 1000 * 60 * 10)]],
        ['createdAt' => ['$gt' => new MongoDB\BSON\UTCDateTime(time() * 1000 - 1000 * 60 * 60 * 24 * 60)]]
        ]
    ]],
    ['$project' => ['_id' => 1,'createdAt' => 1]],
    ['$sort' => ['createdAt' => -1]]
];

$result = $mongoDBLink->selectCollection('position')->aggregate($pipeline);
$records = '';
foreach ($result as $record) {
    $createdAt = date('c', $record->createdAt->__toString() / 1000);
    $records .= "Position: $record->_id, Created: $createdAt\n";
}

if ('' !== $records) {
    echo($records);
    $message = "Status 0 positions:\n$records";
    $title = "Status 0 positions";
    $notification->sendToSlack($title, $message, 'zignaly-broken-positions');
}
