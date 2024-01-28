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

class RedisHandler
{
    private $redisClient;
    private $redisHost1 = false;
    private $redisHost2 = false;
    private $redisHost = false;
    private $redisPort = false;
    private $redisPass = false;
    private $dbName = false;
    private $Monolog;

    public function __construct(Monolog $Monolog, $dbName = 'Zignaly')
    {
        $container = DIContainer::getContainer();
        if ($container->has('monolog')) {
            $this->Monolog = $container->get('monolog');
        } else {
            $this->Monolog = $Monolog;
        }
        $this->dbName = $dbName;
        $this->setRedisHost();
        $this->redisClient = new Redis();
        $this->redisClient->connect($this->redisHost, $this->redisPort);
        $this->redisClient->auth($this->redisPass);
    }

    public function __destruct()
    {
        $this->redisClient->close();
    }

    private function setRedisHost()
    {
        if (!$this->redisHost) {
            switch ($this->dbName) {
                case 'ZignalyLastPrices':
                    $this->redisHost1 = REDIS_HOST1_ZLP;
                    $this->redisHost2 = REDIS_HOST2_ZLP;
                    $this->redisPort = REDIS_PORT_ZLP;
                    $this->redisPass = REDIS_PASS_ZLP;
                    break;
                case 'ZignalyData':
                    $this->redisHost1 = REDIS_HOST1_ZD;
                    $this->redisHost2 = REDIS_HOST2_ZD;
                    $this->redisPort = REDIS_PORT_ZD;
                    $this->redisPass = REDIS_PASS_ZD;
                    break;
                case 'Last3DaysPrices':
                    $this->redisHost1 = REDIS_HOST1_ZL3DP;
                    $this->redisHost2 = REDIS_HOST2_ZL3DP;
                    $this->redisPort = REDIS_PORT_ZL3DP;
                    $this->redisPass = REDIS_PASS_ZL3DP;
                    break;
                case 'ClosedPositions':
                case 'PublicCache':
                    $this->redisHost1 = REDIS_HOST1_ZPC;
                    $this->redisHost2 = REDIS_HOST2_ZPC;
                    $this->redisPort = REDIS_PORT_ZPC;
                    $this->redisPass = REDIS_PASS_ZPC;
                    break;
                case 'ZignalyUpdateSignals':
                case 'ZignalyPriceUpdate':
                case 'ZignalyQueue':
                    $this->redisHost1 = REDIS_HOST1_ZQ;
                    $this->redisHost2 = REDIS_HOST2_ZQ;
                    $this->redisPort = REDIS_PORT_ZQ;
                    $this->redisPass = REDIS_PASS_ZQ;
                    break;
                case 'ZignalyQueueTest':
                    $this->redisHost1 = REDIS_HOST1_ZQT;
                    $this->redisHost2 = REDIS_HOST2_ZQT;
                    $this->redisPort = REDIS_PORT_ZQT;
                    $this->redisPass = REDIS_PASS_ZQT;
                    break;
                case 'RedisTriggersWatcher':
                    $this->redisHost1 = REDIS_HOST1_RTW;
                    $this->redisHost2 = REDIS_HOST2_RTW;
                    $this->redisPort = REDIS_PORT_RTW;
                    $this->redisPass = REDIS_PASS_RTW;
                    break;
                case 'AccountStreamUpdates':
                    $this->redisHost1 = REDIS_HOST1_ASU;
                    $this->redisHost2 = REDIS_HOST2_ASU;
                    $this->redisPort = REDIS_PORT_ASU;
                    $this->redisPass = REDIS_PASS_ASU;
                    break;
                case 'ZignalyLocks':
                    $this->redisHost1 = REDIS_HOST1_ZL;
                    $this->redisHost2 = REDIS_HOST2_ZL;
                    $this->redisPort = REDIS_PORT_ZL;
                    $this->redisPass = REDIS_PASS_ZL;
                    break;
                default:
                    $this->Monolog->sendEntry('error', 'No Redis name selected');

                    return false;
            }
            $hosts = [$this->redisHost1, $this->redisHost2];
            $key = array_rand($hosts);
            $this->redisHost = $hosts[$key];
        } else {
            $this->redisHost = $this->redisHost == $this->redisHost1 ? $this->redisHost2 : $this->redisHost1;
        }
    }

    /**
     * @param string $key
     * @param string $value
     * @param int|array $timeout
     * @param bool $skipCheckConnection
     * @return bool
     */
    public function addKey(string $key, string $value, $timeout, $skipCheckConnection = false)
    {
        return $this->redisClient->set($key, $value, $timeout);
    }

    public function addSet($key, $member)
    {
        return $this->redisClient->sAdd($key, $member);
    }

    /**
     * Add a member to a sorted set.
     * @param $key
     * @param $score
     * @param $member
     * @param bool $skipCheckConnection
     * @param string $options
     * @return int
     */
    public function addSortedSet($key, $score, $member, $skipCheckConnection = false, $options = 'NX')
    {
        return $this->redisClient->zAdd($key, [$options], $score, $member);
    }

    public function addSortedSetPipeline($key, $pipeline, $option = 'NX')
    {
        $pipe = $this->redisClient->multi(Redis::PIPELINE);
        foreach ($pipeline as $value => $score) {
            $pipe->zAdd($key, [$option], $score, $value);
        }

        $pipe->exec();
    }

    /**
     * Compose a pipeline redis batch for sorted set.
     * @param array $data
     */
    public function addSortedSetPipelineWithDynamicData(array $data)
    {
        $pipe = $this->redisClient->multi(Redis::PIPELINE);
        foreach ($data as $datum) {
            $key = $datum['key'];
            $option = $datum['option'];
            $score = $datum['score'];
            $value = $datum['value'];
            $pipe->zAdd($key, [$option], $score, $value);
        }

        $pipe->exec();
    }

    /**
     * Remove the members from the array in the given key.
     * @param string $key
     * @param array $pipeline
     */
    public function remMemberInSortedSetPipeline(string $key, array $pipeline)
    {
        $pipe = $this->redisClient->multi(Redis::PIPELINE);
        foreach ($pipeline as $member) {
            $pipe->zRem($key, $member);
        }

        $pipe->exec();
    }

    /**
     * Remove all redis keys, only if it's lando.
     *
     * @return bool
     */
    public function flushAll()
    {
        if (getenv('LANDO') === 'ON') {
            return $this->redisClient->flushAll();
        }

        return false;
    }

    /**
     * Remove the given key from the redis db.
     * @param string $key
     * @return int
     */
    public function removeKey(string $key): int
    {
        return $this->redisClient->del($key);
    }

    /**
     * Remove the given member from key sorted set
     *
     * @param string $key
     * @param string $member
     * @return int
     */
    public function removeMemberFromList(string $key, string $member)
    {
        return $this->redisClient->lRem($key, $member, 0);
    }

    /**
     * Remove the given member from key sorted set
     *
     * @param string $key
     * @param string $member
     * @return int
     */
    public function removeMemberFromSortedSet(string $key, string $member)
    {
        return $this->redisClient->zRem($key, $member);
    }

    public function removeSortedSet($key, $from = null, $to = null, $skipCheckConnection = true)
    {
        return $this->redisClient->zRemRangeByScore($key, ($from === null) ? '-inf' : $from, ($to === null) ? '+inf' : $to);
    }

    private function checkIfConnectionAliveOrReconnect()
    {
        try {
            if ($this->redisClient->ping() == '+PONG') {
                return true;
            }
        } catch (Exception $e) {
            $this->Monolog->sendEntry('error', 'Redis connection failed: ' . $e->getMessage());
            $this->setRedisHost();
            $this->redisClient->connect($this->redisHost, $this->redisPort);
            $this->redisClient->auth($this->redisPass);

            return $this->redisClient->ping() == '+PONG';
        }

        return false;
    }

    /**
     * Delete the given key from the db.
     * @param $key
     * @return int
     */
    public function delKey($key)
    {
        return $this->redisClient->del($key);
    }

    public function checkIfMemberExistsInSortedSet($key, $member, $skipCheckConnection = false)
    {
        return !$this->redisClient->zScore($key, $member) == null;
    }

    public function getHash($key, $field)
    {
        return $this->redisClient->hGet($key, $field);
    }

    public function getHashAll($key)
    {
        return $this->redisClient->hGetAll($key);
    }

    /**
     * @param string $key
     * @param bool $skipCheckConnection
     * @return bool|string
     */
    public function getKey(string $key, $skipCheckConnection = false)
    {
        return $this->redisClient->get($key);
    }

    /**
     * Get all keys from the db.
     * @param string $pattern
     * @return array
     */
    public function getAllKeys(string $pattern)
    {
        return $this->redisClient->keys($pattern);
    }

    /**
     * Return all elements from the list.
     *
     * @param string $key
     * @return array
     */
    public function getAllElementsFromList(string $key)
    {
        return $this->redisClient->lRange($key, 0, -1);
    }

    /**
     * Return all elements from the set
     *
     * @param string $key
     * @param bool $withScores
     * @return array
     */
    public function getAllElementsFromSet(string $key, bool $withScores)
    {
        return $this->redisClient->zRange($key, 0, -1, $withScores);
    }

    /**
     * Check if is the turn of the given member for the given set.
     *
     * @param string $key
     * @param string $originalMember
     * @param string $processName
     * @return bool
     */
    private function checkHardLockTurn(string $key, string $originalMember, string $processName)
    {
        $set = $this->getAllElementsFromSet($key, true);

        if (empty($set)) {
            $this->redisClient->zAdd($key, $this->composeScoreForQueueLocks(), $originalMember);
            return true;
        }


        foreach ($set as $member => $score) {
            list($startTime, $lastUpdateTime) = explode('.', $score);
            $lastUpdateTime = $lastUpdateTime / 10000;
            list($process, $hostName, $lockType, $estimatedLockedTime) = explode(':', $member);
            if (empty($estimatedLockedTime)) {
                $estimatedLockedTime = 60;
            }
            if ('hard' === $lockType) {
                if ($process === $processName && $hostName === gethostname()) {
                    //$this->redisClient->zAdd($key, $this->composeScoreForQueueLocks($startTime), $member);
                    return true;
                }

                if ($lastUpdateTime < time() - $estimatedLockedTime || $lastUpdateTime > time() + 3600) {
                    $this->redisClient->zrem($key, $member);
                }

                return false;
            }
        }

        return false;
    }

    /**
     * Check if the process for the given key is already asking for the hard from a different server.
     *
     * @param string $key
     * @param string $processName
     * @return bool
     */
    private function checkIfProcessIsAlreadyAskingForLockHard(string $key, string $processName)
    {
        $set = $this->getAllElementsFromSet($key, true);

        $return = false;
        foreach ($set as $member => $score) {
            list($startTime, $lastUpdateTime) = explode('.', $score);
            $lastUpdateTime = $lastUpdateTime / 10000;
            list($process, $hostName, $lockType, $estimatedLockedTime) = explode(':', $member);

            if ($lastUpdateTime < time() - $estimatedLockedTime || $lastUpdateTime > time() + 3600) {
                $this->redisClient->zrem($key, $member);
            } elseif ('hard' === $lockType && $process === $processName) {
                //$this->Monolog->sendEntry('debug', 'Another server is already asking for the hard lock');
                $return = true;
            }
        }

        return $return;
    }

    /**
     * Determine if the member is locked or not.
     *
     * @param string $processName
     * @param string $documentId
     * @param string $collectionName
     * @param int $estimatedLockedTime
     * @return bool
     */
    public function lockHard(string $processName, string $documentId, string $collectionName, int $estimatedLockedTime)
    {
        $key = 'locked' . ucfirst(strtolower($collectionName)) . ':' . $documentId;
        if ($this->checkIfProcessIsAlreadyAskingForLockHard($key, $processName)) {
            return false;
        }

        $member = $processName . ':' . gethostname() . ':hard:' . $estimatedLockedTime;
        $this->redisClient->zAdd($key, $this->composeScoreForQueueLocks(), $member);

        $startingAt = time();
        do {
            if ($this->checkHardLockTurn($key, $member, $processName)) {
                return true;
            }
            sleep(1);
        } while ($startingAt > time() - 120);

        return false;
    }



    /**
     * Perform a soft lock only if the lock already doesn't exists for the given position and process.
     *
     * @param string $processName
     * @param string $positionId
     * @return bool
     */
    public function lockPositionSoft(string $processName, string $positionId)
    {
        $key = 'lockedPosition:' . $positionId;
        if (!$this->checkIfProcessNameExistsInSet($key, $processName)) {
            $member = $processName . ':' . gethostname() . ':soft:60';
            return $this->redisClient->zAdd($key, $this->composeScoreForQueueLocks(), $member) == 1;
        }

        return false;
    }

    /**
     * Remove the locks from the given position.
     *
     * @param string $processName
     * @param string $positionId
     * @param string $type
     * @param string $collectionName
     * @param int $estimatedLockedTime
     * @return int
     */
    public function removeLocks(string $processName, string $positionId, string $type, string $collectionName, int $estimatedLockedTime)
    {
        $key = 'locked' . ucfirst(strtolower($collectionName)) . ':' . $positionId;
        $memberHard = $processName . ':' . gethostname() . ':hard:'.$estimatedLockedTime;
        $memberSoft = $processName . ':' . gethostname() . ':soft:60';

        if ($type == 'all') {
            return $this->redisClient->zRem($key, $memberHard, $memberSoft);
        } else if ($type == 'soft') {
            return $this->redisClient->zRem($key, $memberSoft);
        } else {
            return $this->redisClient->zRem($key, $memberHard);
        }
    }

    /**
     * @param string $key
     * @param string $member
     * @return int
     */
    public function zrank(string $key, string $member)
    {
        return $this->redisClient->zRank($key, $member);
    }

    /**
     * Compose the score value for queue locks.
     *
     * @param bool|float $score
     * @return float
     */
    private function composeScoreForQueueLocks($score = false)
    {
        if (!$score) {
            do {
                $insertTime = time();
                $truncatedInsertTime = substr($insertTime, -4, 4);
            } while (empty($truncatedInsertTime));
        } else {
            list($truncatedInsertTime) = explode('.', $score);
        }

        $newScore = $truncatedInsertTime . '.' . microtime(true) * 10000;

        return (float)$newScore;
    }

    /**
     * Check if the processName exists inside any element for the given set.
     *
     * @param string $key
     * @param string $processName
     * @return bool
     */
    private function checkIfProcessNameExistsInSet(string $key, string $processName)
    {
        $set = $this->redisClient->zRange($key, 0, -1, true);
        if (empty($set)) {
            return false;
        }

        $lastUpdateTime = 0;
        foreach ($set as $member) {
            if (is_numeric($member)) {
                list(, $lastUpdateTime) = explode('.', $member);
            } else {
                list($process) = explode(':', $member);
                if ($process == $processName) {
                    if ($lastUpdateTime < time() - 60) {
                        $this->redisClient->zrem($key, $member);
                    } else {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Return the list of keys from the array.
     *
     * @param array $keys
     * @return array
     */
    public function mGetKey(array $keys)
    {
        return $this->redisClient->mget($keys);
    }

    public function getScoringFromSortedSet($key, $member)
    {


        return $this->redisClient->zScore($key, $member);
    }

    public function redisPing()
    {
        return $this->redisClient->ping();
    }

    /**
     * Return the first element from a list, removing it from the list or block the list for 20 seconds to see if any
     * new element is added to the list.
     *
     * @param string $key
     * @return array
     */
    public function popFromListOrBlock(string $key)
    {
        return $this->redisClient->blPop($key, 20);
    }

    /**
     * Insert element at the tail of the list
     *
     * @param string $key
     * @param string $element
     * @return bool|int
     */
    public function insertElementInList(string $key, string $element)
    {
        return $this->redisClient->rPush($key, $element);
    }

    /**
     * Pop a message from the queue.
     *
     * @param $key
     * @return mixed
     */
    public function popFromSetOrBlock($key)
    {
        return $this->redisClient->bzPopMin($key, 1, 20);
    }

    public function popManyFromSet($key, $count)
    {
        return $this->redisClient->zPopMin($key, $count);
    }

    public function popFromSet($key)
    {

        return $this->redisClient->sPop($key);
    }

    public function removeHashMember($key, $member)
    {
        return $this->redisClient->hDel($key, $member);
    }

    public function setMultipleHashes($key, $members, $skipCheckConnection = false)
    {


        return $this->redisClient->hMSet($key, $members) !== false;
    }

    public function setHash($key, $hashKey, $value, $skipCheckConnection = false)
    {


        return $this->redisClient->hSet($key, $hashKey, $value) !== false;
    }

    public function keys($search, $skipCheckConnection = false)
    {


        return $this->redisClient->keys($search);
    }

    public function zRevRangeByScore($key, $scoreFrom, $scoreTo, $limit = null, $withScores = false, $skipCheckConnection = false)
    {

        $params = [];
        if ($limit != null) {
            $params['limit'] = $limit;
        }
        if ($withScores) {
            $params['withscores'] = true;
        }

        return $this->redisClient->zRevRangeByScore($key, $scoreFrom, $scoreTo, $params);
    }

    public function zRangeByScore($key, $scoreFrom, $scoreTo, $limit = null, $withScores = false, $skipCheckConnection = false)
    {
        $params = [];
        if ($limit != null) {
            $params['limit'] = $limit;
        }
        if ($withScores) {
            $params['withscores'] = true;
        }

        return $this->redisClient->zRangeByScore($key, $scoreFrom, $scoreTo, $params);
    }

    public function zRem($key, $element)
    {
        return $this->redisClient->zRem($key, $element);
    }

    public function zCount($key, $scoreFrom, $scoreTo, $skipCheckConnection = false)
    {
        return $this->redisClient->zCount($key, $scoreFrom, $scoreTo);
    }

    public function zCard($key)
    {
        return $this->redisClient->zCard($key);
    }
}
