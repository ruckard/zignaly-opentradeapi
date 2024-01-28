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
 * Importer of trades from exchange: loads CSV files containing prices into MySQL.
 * 
 * This script takes two arguments:
 *   - exchange name (mandatory): ascendex|binance-spot|binance-futures|bitmex|kucoin|vcce
 *   - date suffix (optional): datetime ten-minute string formatted like: 2021-01-01-08-0x
 * If not specified, date suffix will be setted to current period.
 * 
 * Schedule this script in crontab to run every 10 minute in each server running process/tradesFromExchange.php
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
    $date = getTenMinutesSuffix();
}

$exchange = getExchangeFromConfiguration($exchangeCode);

if (empty($exchange)) {
    exit("Unrecognized exchange: $exchangeCode.\n");
}

if (!commandExists('mysql')) {
    exit("Please install mysql client: 'apt-get install default-mysql-client'.\n");
}

$Monolog = new Monolog($exchange['log']);

$files = getSqlTradeFiles($exchange['store'], $date);

$mysqlCommand = getMySqlCommand();

foreach ($files as $file) {
    $parts = explode('-', $file);
    $file = "/zignaly/sql/{$file}";
    if (insertSqlFiles($exchange['store'], $parts[1], $file, $Monolog)) {
        unlink($file);
    }
}
