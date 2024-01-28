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


use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQ
{
    private $connection;
    private $channel;
    private $host = RABBIT_HOST;
    private $user = RABBIT_USER;
    private $pass = RABBIT_PASS;
    private $vhost = RABBIT_VHOST;
    private $port = RABBIT_PORT;

    function __construct()
    {
        $this->connection = new AMQPStreamConnection(
            $this->host,
            $this->port,
            $this->user,
            $this->pass,
            $this->vhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            20.0,
            60.0,
            null,
            true,
            0
        );

        $this->channel = $this->connection->channel();
        $this->channel->queue_declare('signals', false, true, false, false);
        $this->channel->queue_declare('takeProfit', false, true, false, false);
        $this->channel->queue_declare('takeProfit_Demo', false, true, false, false);
        $this->channel->queue_declare('stopLoss', false, true, false, false);
        $this->channel->queue_declare('stopLoss_Demo', false, true, false, false);
        $this->channel->queue_declare('profileNotifications', false, true, false, false);
        $this->channel->queue_declare('withdrawals', false, true, false, false);
    }

    function __destruct()
    {
        $this->channel->close();
        $this->connection->close();
    }

    public function publishMsg($queue, $message)
    {
        $msg = new AMQPMessage($message, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);

        $this->channel->basic_publish($msg, '', $queue);
    }

    public function consumeMsg($queue, $callback, $noAck = false)
    {
        $this->channel->basic_qos(null, 1, null);
        $consumerTag = gethostname();
        $this->channel->basic_consume($queue, $consumerTag, false, $noAck,
            false, false, $callback);

        while(count($this->channel->callbacks)) {
            $this->channel->wait();
            $this->connection->getIO()->read(0);
        }
    }
}
