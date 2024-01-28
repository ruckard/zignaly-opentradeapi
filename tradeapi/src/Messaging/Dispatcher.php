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


namespace Zignaly\Messaging;

use Zignaly\Messaging\Messages\AccountingDone;
use Zignaly\Messaging\Messages\RecreateClosedPositions;
use Zignaly\Messaging\Messages\RegisterClosedPosition;
use Zignaly\Messaging\Messages\UpdateRemoteClosedPositions;
use Zignaly\Messaging\Messages\UserLogged;

/**
 * Class Dispatcher
 *
 * @package Zignaly\Messaging
 */
class Dispatcher
{
    /**
     * @var \RedisHandler
     */
    private $transport;

    /**
     * Dispatcher constructor.
     */
    public function __construct(\RedisHandler $transport)
    {
        $this->transport = $transport;
    }

    public function sendAccountingDone(AccountingDone $message)
    {
        $this->sendMessage('accountingDoneQueue', $message);
    }

    public function sendRecreateClosedPositions(RecreateClosedPositions $message)
    {
        $this->sendMessage('closedPositionsQueue', $message);
    }

    public function sendRegisterClosedPosition(RegisterClosedPosition $message)
    {
        $this->sendMessage('registerPositionQueue', $message);
    }

    public function sendUpdateEdgeCache(UpdateRemoteClosedPositions $message)
    {
        $this->sendMessage('updateCachePositionQueue', $message);
    }

    public function sendUserLogged(UserLogged $message)
    {
        $this->sendMessage('userLoggedQueue', $message);
    }

    private function sendMessage(string $queueName, Message $message)
    {
        $this->transport->addSortedSet($queueName, time(), (string)$message, true);
    }
}