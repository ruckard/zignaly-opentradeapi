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


class RabbitMQRedisProxyMessage
{
    /** @var RabbitMqRedisProxy */
    private $proxy;
    /** @var string */
    private $queue;
    /** @var string */
    private $origMsg;
    /** @var string */
    public $body;
    public $delivery_info;

    public function __construct(
        RabbitMQ $proxy,
        string $queue,
        string $msg,
        bool $noAck
    ) {
        $this->proxy = $proxy;
        $this->queue = $queue;
        $this->origMsg = $msg;
        $this->body = $msg;
        $this->noAck = $noAck;
        $this->delivery_info['channel'] = $this;
        $this->delivery_info['delivery_tag'] = $msg;
        $this->requeue = !$noAck;
    }

    public function basic_ack($delivery_tag, $multiple = false)
    {
        $this->requeue = false;
    }

    public function basic_nack($delivery_tag, $multiple = false, $requeue = false)
    {
        $this->requeue = $requeue;
    }

    public function __destruct()
    {
        if ($this->requeue) {
            $this->proxy->publishMsg($this->queue, $this->origMsg);
            $this->requeue = false;
        }
    }
}
