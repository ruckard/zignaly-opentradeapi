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

class RabbitMQ
{
    /** @var RedisHandler */
    private $redisHandlerZignalyQueue;

    public function __construct()
    {
        $this->redisHandlerZignalyQueue = null;
    }

    /**
     *
     * @return RedisHandler
     */
    private function getRedisHandler()
    {
        if (null == $this->redisHandlerZignalyQueue) {
            $container = DIContainer::getContainer();
            $this->redisHandlerZignalyQueue = $container->get('redis.queue');
        }

        return $this->redisHandlerZignalyQueue;
    }

    public function publishMsg($queue, $message)
    {
        $this->getRedisHandler()->addSortedSet($queue, time(), $message, true);
    }

    public function consumeMsg($queue, $callback, $noAck = false)
    {
        do {
            $popMember = $this->getRedisHandler()->popFromSetOrBlock($queue);
            if ($popMember == null) {
                $ret = call_user_func($callback, null);
            } else {
                $msg = new RabbitMQRedisProxyMessage($this, $queue, $popMember[1], $noAck);
                try {
                    $ret = call_user_func($callback, $msg);
                } catch (Exception $ex) {

                }
                $msg = null;
                while (gc_collect_cycles());
            }
        } while (false !== $ret);
    }
}
