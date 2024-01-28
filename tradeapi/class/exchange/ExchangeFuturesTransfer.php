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


namespace Zignaly\exchange;

/**
 * Class ExchangeFuturesTransfer
 * @package Zignaly\exchange
 */
class ExchangeFuturesTransfer
{
    public const TYPE_DEPOSIT = 1;
    public const TYPE_WITHDRAWAL = 2;

    /**
     * @var int
     */
    private $timestamp;

    /**
     * @var mixed
     */
    private $transferId;

    /**
     * @var int
     */
    private $type;

    /**
     * @var float
     */
    private $amount = 0.0;

    /**
     * @return int
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * @param int $timestamp
     */
    public function setTimestamp(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    /**
     * @return mixed
     */
    public function getTransferId()
    {
        return $this->transferId;
    }

    /**
     * @param mixed $transferId
     */
    public function setTransferId($transferId): void
    {
        $this->transferId = $transferId;
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * @param int $type
     */
    public function setType(int $type): void
    {
        $this->type = $type;
    }

    /**
     * @return float
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * @param mixed $amount
     */
    public function setAmount($amount): void
    {
        $this->amount = (float) $amount;
    }

    /**+
     * @return bool
     */
    public function isDeposit():bool
    {
        return self::TYPE_DEPOSIT === $this->type;
    }

    /**
     * @return bool
     */
    public function isWithdrawal():bool
    {
        return self::TYPE_WITHDRAWAL === $this->type;
    }
}
