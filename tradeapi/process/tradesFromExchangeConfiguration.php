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


function getExchangeFromConfiguration(string $code): array
{
    $exchanges = [
        'ascendex' => [ 
            'log' => 'tradesFromAscendEX',
            'queue' => 'AscendEXTrades',
            'store' => 'AscendEXTrade',
            'name' => 'ascendex',
        ],
        'binance-spot' => [ 
            'log' => 'tradesFromBinance',
            'queue' => 'BinanceTrades',
            'store' => 'BinanceTrade',
            'name' => 'binance',
        ],
        'binance-futures' => [ 
            'log' => 'tradesFromBinanceFutures',
            'queue' => 'BinanceFuturesTrades',
            'store' => 'BinanceFuturesTrade',
            'name' => 'binance',
        ],
        'bitmex' => [ 
            'log' => 'tradesFromBitMEX',
            'queue' => 'BitMEXTrades',
            'store' => 'BitMEXTrade',
            'name' => 'bitmex',
        ],
        'kucoin' => [ 
            'log' => 'tradesFromKuCoin',
            'queue' => 'KuCoinTrades',
            'store' => 'KuCoinTrade',
            'name' => 'kucoin',
        ],
        'vcce' => [ 
            'log' => 'tradesFromVCCE',
            'queue' => 'VCCETrades',
            'store' => 'VCCETrade',
            'name' => 'vcce',
        ],
    ];
    if (empty($exchanges[$code])) {
        return [];
    }
    return $exchanges[$code];
}

function getTradesCsvFileName(string $store, ?string $date = null): string
{
    if (null === $date) {
        $date = date('Y-m-d-H-i');
    }
    return sprintf('/zignaly/csv/%s-%s.csv', $store, $date);
}

function getTradesSqlFileName(string $store, string $symbol, ?string $date = null): string
{
    if (null === $date) {
        $date = getTenMinutesSuffix();
    }
    return sprintf('/zignaly/sql/%s-%s-%s.csv', $store, $symbol, $date);
}

// Common functions

function getTenMinutesSuffix()
{
    $datetime = new \DateTime();
    $minute = intval($datetime->format("i") / 10);
    return $datetime->format("Y-m-d-H-{$minute}x");
}

function getSqlTradeFiles(string $store, string $excludeDate)
{
    $files  = scandir('/zignaly/sql');
    shuffle($files);
    return array_values(array_filter($files, function($f) use ($store, $excludeDate) {
        return (strpos($f, $store) === 0) && (strpos($f, $excludeDate) === false);
    }));
}

function getHistoryDBUri($escapePassword = false): string
{
    $password = $escapePassword ? urlencode(MONGODB_HISTORY_OPTIONS['password']) : MONGODB_HISTORY_OPTIONS['password'];
    return sprintf(
        "mongodb://%s:%s@%s/%s?authSource=%s",
        MONGODB_HISTORY_OPTIONS['username'],
        $password,
        implode(',', MONGODB_MAIN_HOSTS),
        MONGODB_HISTORY_NAME,
        MONGODB_HISTORY_OPTIONS['authSource']
    );
}

function commandExists(string $cmd): bool
{
    $return = shell_exec(sprintf("which %s", escapeshellarg($cmd)));
    return !empty($return);
}

// SQL functions

function getMySqlCommand(): array
{
    return [
        'mysql',
        '-h', MYSQL_TRADES_SERVER,
        '-P', MYSQL_TRADES_PORT,
        '-u'.MYSQL_TRADES_USER,
        "-p'".MYSQL_TRADES_PASSWORD."'",
        MYSQL_TRADES_DATABASE,
        '-v',
    ];
}

function getNormalTableName(string $store, string $symbol): string
{
    return "{$store}_{$symbol}";
}

function getArchiveTableName(string $store, string $symbol): string
{
    return "{$store}_{$symbol}_archive";
}

function getCreateTable(string $name): string
{
    $create = "CREATE TABLE IF NOT EXISTS \`{$name}\` (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        datetime datetime(3) NOT NULL,
        price decimal(60,10) NOT NULL DEFAULT 0.0,
        PRIMARY KEY (id),
        UNIQUE KEY datetime_uk (datetime,price)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
    return str_replace("\n", " ", $create);
}

function insertSqlFiles(string $store, string $symbol, string $file, $Monolog): bool
{
    $mysqlCommand = getMySqlCommand();

    // Insert trades in normal table

    $normalTableName = getNormalTableName($store, $symbol);
    $createNormalTableSql = getCreateTable($normalTableName);
    $createNormalTableCommand = [
        '-e',
        "\"{$createNormalTableSql}\"",
    ];
    $cmd = implode(' ', array_merge($mysqlCommand, $createNormalTableCommand));
    exec($cmd, $output, $return);

    $importNormalTableCommand = [
        '-e',
        "\"LOAD DATA LOCAL INFILE '{$file}' INTO TABLE \`{$normalTableName}\` FIELDS TERMINATED BY ',' (datetime,price)\"",
    ];
    $cmd = implode(' ', array_merge($mysqlCommand, $importNormalTableCommand));
    exec($cmd, $output, $return);

    if (0 !== $return) {
        //$Monolog->sendEntry('info', "Successfully imported: {$file} into table: {$normalTableName}");
    //} else {
        $Monolog->sendEntry('warning', "Something went wrong importing: {$file} into table: {$normalTableName}");
        return false;
    }

    // Insert trades in archive table

    $archiveTableName = getArchiveTableName($store, $symbol);
    $createArchiveTableSql = getCreateTable($archiveTableName);
    $createArchiveTableCommand = [
        '-e',
        "\"{$createArchiveTableSql}\"",
    ];

    $cmd = implode(' ', array_merge($mysqlCommand, $createArchiveTableCommand));
    exec($cmd, $output, $return);

    $importArchiveTableCommand = [
        '-e',
        "\"LOAD DATA LOCAL INFILE '{$file}' INTO TABLE \`{$archiveTableName}\` FIELDS TERMINATED BY ',' (datetime,price)\"",
    ];
    $cmd = implode(' ', array_merge($mysqlCommand, $importArchiveTableCommand));
    exec($cmd, $output, $return);

    if (0 === $return) {
        $Monolog->sendEntry('info', "Successfully imported: {$file} into table: {$archiveTableName}");
    } else {
        $Monolog->sendEntry('warning', "Something went wrong importing: {$file} into table: {$archiveTableName}");
        return false;
    }

    return true;
}