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


class HistoryDB2 extends HistoryDB
{
    const ARCHIVE_TABLE_SUFFIX = '_archive';

    const MAX_HISTORY_DB = '30 days';

    private $mysql;

    public function __construct()
    {
        $this->mysql = null;
    }

    public function findPriceAt($collectionName, $symbol, $datetime, $toString = false)
    {
        $from = is_object($datetime) ? $datetime : new \MongoDB\BSON\UTCDateTime($datetime);

        $from = $from->toDateTime();
        $maxHistoryDays = (new DateTime())
                ->sub(DateInterval::createFromDateString(self::MAX_HISTORY_DB));

        $tableName = $collectionName.'_'.$symbol;

        if ($from < $maxHistoryDays) {
            $tableName .= self::ARCHIVE_TABLE_SUFFIX;
        }

        $from = $from->format('Y-m-d H:i:s.u');

        $sql = <<<SQL
            SELECT * FROM `{$tableName}` WHERE `datetime` >= '{$from}' ORDER BY `datetime` ASC LIMIT 1;
        SQL;

        $trades = $this->getTrades($sql);

        foreach ($trades as $trade) {
            return $trade->price;
        }

        return false;
    }

    public function getLastPrice($exchangeName, $symbol)
    {
        $tableName = $exchangeName.'Trade_'.$symbol;
        $sql = <<<SQL
            SELECT * FROM `{$tableName}` ORDER BY `datetime` DESC LIMIT 1;
        SQL;

        $trades = $this->getTrades($sql);

        foreach ($trades as $trade) {
            return $trade->price;
        }

        return false;
    }

    public function findTradeBetween($collectionName, $symbol, $datetime, $datetime2, $limit = 100)
    {
        $from = is_object($datetime) ? $datetime : new \MongoDB\BSON\UTCDateTime($datetime);
        $to = is_object($datetime2) ? $datetime2 : new \MongoDB\BSON\UTCDateTime($datetime2);

        $from = $from->toDateTime();
        $to = $to->toDateTime();

        $maxHistoryDays = (new DateTime())
            ->sub(DateInterval::createFromDateString(self::MAX_HISTORY_DB));

        $tableName = $collectionName.'_'.$symbol;

        if ($from < $maxHistoryDays) {
            $tableName .= self::ARCHIVE_TABLE_SUFFIX;
        }

        $from = $from->format('Y-m-d H:i:s.u');
        $to = $to->format('Y-m-d H:i:s.u');

        $sql = <<<SQL
            SELECT *,FLOOR(UNIX_TIMESTAMP(datetime)*1000) as datetime from `{$tableName}` WHERE `datetime` >= '{$from}' AND `datetime` <= '{$to}' ORDER BY `datetime` ASC LIMIT {$limit};
        SQL;

        $trades = $this->getTrades($sql);
        return $trades;
    }

    public function findTradeBeforeOrAfter($collectionName, $symbol, $datetime, $before = false)
    {
        $from = is_object($datetime) ? $datetime : new \MongoDB\BSON\UTCDateTime($datetime);

        $from = $from->toDateTime();
        $maxHistoryDays = (new DateTime())
            ->sub(DateInterval::createFromDateString(self::MAX_HISTORY_DB));

        $tableName = $collectionName.'_'.$symbol;

        if (($from < $maxHistoryDays) && !$before) {
            $tableName .= self::ARCHIVE_TABLE_SUFFIX;
        }

        $from = $from->format('Y-m-d H:i:s.u');

        $where = "`datetime` >= '{$from}'";
        $orderBy = 'ASC';
        if ($before) {
            $where = "`datetime` <= '{$from}'";
            $orderBy = 'DESC';
        }
        $sql = <<<SQL
            SELECT *,FLOOR(UNIX_TIMESTAMP(datetime)*1000) as datetime from `{$tableName}` WHERE {$where} ORDER BY `datetime` {$orderBy} LIMIT 1;
        SQL;

        $trades = $this->getTrades($sql);

        foreach ($trades as $trade) {
            // $trade = $this->convertTradeDateTimeToISO($trade);
            $trade->symbol = $symbol;
            return $trade;
        }

        return false;
    }

    public function getFirstTradePriceAfterTimestamp (int $timestamp, string $collection, string $symbol, float $price, bool $isBuy)
    {
        $timestamp = is_object($timestamp) ? $timestamp : new \MongoDB\BSON\UTCDateTime($timestamp);

        $timestamp = $timestamp->toDateTime();
        $maxHistoryDays = (new DateTime())
            ->sub(DateInterval::createFromDateString(self::MAX_HISTORY_DB));

        $tableName = $collection.'_'.$symbol;

        if (($timestamp < $maxHistoryDays) && !$isBuy) {
            $tableName .= self::ARCHIVE_TABLE_SUFFIX;
        }

        $timestamp = $timestamp->format('Y-m-d H:i:s.u');

        if ($isBuy) {
            $sql = <<<SQL
                SELECT *,FLOOR(UNIX_TIMESTAMP(datetime)*1000) as datetime FROM `{$tableName}` WHERE `datetime` > '{$timestamp}' AND `price` <= '{$price}' ORDER BY `datetime`  ASC LIMIT 1
            SQL;
        } else {
            $sql = <<<SQL
                SELECT *,FLOOR(UNIX_TIMESTAMP(datetime)*1000) as datetime FROM `{$tableName}` WHERE `datetime` > '{$timestamp}' AND `price` >= '{$price}' ORDER BY `datetime`  ASC LIMIT 1
            SQL;
        }

        $trades = $this->getTrades($sql);

        foreach ($trades as $trade) {
            //$trade = $this->convertTradeDateTimeToISO($trade);
            $trade->symbol = $symbol;
            return $trade;
        }

        return false;
    }

    public function getExtremePriceWithDate($collectionName, $symbol, $from, $to, $min = true)
    {
        $from = is_object($from) ? $from : new \MongoDB\BSON\UTCDateTime($from);
        $to = is_object($to) ? $to : new \MongoDB\BSON\UTCDateTime($to);

        $from = $from->toDateTime();
        $to = $to->toDateTime();

        $maxHistoryDays = (new DateTime())
            ->sub(DateInterval::createFromDateString(self::MAX_HISTORY_DB));

        $tableName = $collectionName.'_'.$symbol;

        if ($from < $maxHistoryDays) {
            $tableName .= self::ARCHIVE_TABLE_SUFFIX;
        }

        $from = $from->format('Y-m-d H:i:s.u');
        $to = $to->format('Y-m-d H:i:s.u');

        $orderBy = $min ? 'ASC' : 'DESC';

        $sql = <<<SQL
            SELECT *,FLOOR(UNIX_TIMESTAMP(datetime)*1000) as datetime from `{$tableName}` WHERE `datetime` >= '{$from}' AND `datetime` <= '{$to}' ORDER BY `price` {$orderBy} LIMIT 1;
        SQL;

        $trades = $this->getTrades($sql);

        $price = 0;
        $datetime = 0;

        foreach ($trades as $trade) {
            // $trade = $this->convertTradeDateTimeToISO($trade);
            $price = $trade->price;
            $datetime = $trade->datetime;
        }

        return [$price, $datetime];
    }

    public function getPricesAndComposeStats(
        Accounting $Accounting,
        string $collectionName,
        string $symbol,
        object $from,
        object $to,
        $price
    )
    {
        list ($higherPrice, $timeAtHigherPrice) = $this->getExtremePriceWithDate($collectionName, $symbol, $from, $to, false);
        list ($lowerPrice, $timeAtLowerPrice) = $this->getExtremePriceWithDate($collectionName, $symbol, $from, $to);
        list ($lowerBeforeHigherPrice, $timeAtLowerBeforeHigherPrice) =
            $this->getExtremePriceWithDate($collectionName, $symbol, $from, $timeAtHigherPrice);

        $higherPricePercentage = $Accounting->getPercentage($price, $higherPrice);
        $lowerBeforeHigherPricePercentage = $Accounting->getPercentage($price, $lowerBeforeHigherPrice);
        $lowerPricePercentage = $Accounting->getPercentage($price, $lowerPrice);

        return [
            'higherPrice' => $higherPrice,
            'higherPricePercentage' => $higherPricePercentage,
            'timeAtHigherPrice' => new \MongoDB\BSON\UTCDateTime($timeAtHigherPrice),
            'secondsUntilHigherPrice' =>
                round($timeAtHigherPrice / 1000 - $from->__toString() / 1000, 0),
            'lowerBeforeHigherPrice' => $lowerBeforeHigherPrice,
            'lowerBeforeHigherPricePercentage' => $lowerBeforeHigherPricePercentage,
            'timeAtLowerBeforeHigherPrice' => new \MongoDB\BSON\UTCDateTime($timeAtLowerBeforeHigherPrice),
            'secondsUntilLowerBeforeHigherPrice' =>
                round($timeAtLowerBeforeHigherPrice / 1000 - $from->__toString() / 1000, 0),
            'lowerPrice' => $lowerPrice,
            'lowerPricePercentage' => $lowerPricePercentage,
            'timeAtLowerPrice' => new \MongoDB\BSON\UTCDateTime($timeAtLowerPrice),
            'secondsUntilLowerPrice' =>
                round($timeAtLowerPrice / 1000 - $from->__toString() / 1000, 0),
        ];
    }

    private function convertTradeDateTimeToISO($trade)
    {
        $trade->datetime = date('c', strtotime($trade->datetime));
        return $trade;
    }

    private function getTrades($sql)
    {
        try {
            $stmt = $this->getMysql()->prepare($sql);
            $stmt->execute();
            $trades = $stmt->fetchAll(\PDO::FETCH_OBJ);
            $this->closeMysql($stmt);
            return $trades;
        } catch (\PDOException $ex) {
            return [];
        }
    }

    private function getMysql(): \PDO
    {
        if (null === $this->mysql) {
            $this->mysql = new \PDO(SQL_DSN, MYSQL_TRADES_USER, MYSQL_TRADES_PASSWORD);
        }
        return $this->mysql;
    }

    private function closeMysql(&$stmt)
    {
        $stmt = null;
        $this->mysql = null;
    }
}
