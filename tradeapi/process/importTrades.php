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


/**
 * Importer of trades from exchange: loads a CSV file containing prices into MongoDB.
 * 
 * This script takes two arguments:
 *   - exchange name (mandatory): ascendex|binance-spot|binance-futures|bitmex|kucoin|vcce
 *   - date suffix (optional): datetime minute string formatted like: 2021-01-01-08-00
 * If not specified, date suffix will be setted to last minute.
 * 
 * Schedule this script in crontab to run every minute in each server running process/tradesFromExchange.php
 */

use Zignaly\Process\DIContainer;

require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../vendor/autoload.php';
require_once dirname(__FILE__) . '/../class/Monolog.php';
require_once dirname(__FILE__) . '/tradesFromExchangeConfiguration.php';
$container = DIContainer::init();

$exchangeCode = $argv[1] ?? null;
if (null === $exchangeCode) {
    exit("Exchange is mandatory. Please, specify one.\n");
}
$date = $argv[2] ?? null;
if (null === $date) {
    $date = date('Y-m-d-H-i', strtotime('1 minutes ago'));
}

$exchange = getExchangeFromConfiguration($exchangeCode);

if (empty($exchange)) {
    exit("Unrecognized exchange: $exchangeCode.\n");
}

if (!commandExists('mongoimport')) {
    exit("Please install mongoimport: 'sudo apt-get install mongodb-org-tools'.\n");
}

$Monolog = new Monolog($exchange['log']);

$fileName = getTradesCsvFileName($exchange['store'], $date);
$cmd = implode(' ', [
    'mongoimport',
    '--type', 'csv',
    '--quiet',
    '-c', $exchange['store'],
    '-f', 'symbol.string\(\),price.decimal\(\),datetime.date\(\'2006-01-02T15:04:05.000Z\'\)',
    '--columnsHaveTypes',
    '--uri', getHistoryDBUri(true),
    $fileName,
]);
$Monolog->sendEntry('debug', $cmd);

exec($cmd, $output, $return);
if (0 === $return) {
    $Monolog->sendEntry('info', "Successfully imported: $fileName");
    unlink($fileName);
} else {
    $Monolog->sendEntry('warning', "Something went wrong importing: $fileName");
}
return 0;
