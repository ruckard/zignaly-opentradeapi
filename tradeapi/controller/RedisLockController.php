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


use \MongoDB\Model\BSONDocument;
use Zignaly\Process\DIContainer;

class RedisLockController
{
    /**
     * @var Monolog
     */
    private $Monolog;

    /**
     * @var newPositionCCXT
     */
    private $newPositionCCXT;

    /**
     * @var ProfitSharingBalance
     */
    private $ProfitSharingBalance;

    /**
     * @var Provider
     */
    private $Provider;

    /**
     * @var RedisHandler
     */
    private $RedisHandlerZignalyLocks;

    /**
     * @var newUser
     */
    private $newUser;

    private $hardLockId;

    private $softLockId;

    public function __construct()
    {
        $container = DIContainer::getContainer();
        if ($container->has('monolog')) {
            $this->Monolog = $container->get('monolog');
        } else {
            $container->set('monolog', new Monolog('RedisLockController'));
            $this->Monolog = $container->get('monolog');
        }
        $this->RedisHandlerZignalyLocks = $container->get('redis.locks');
        $this->newPositionCCXT = $container->get('newPositionCCXT.model');
        $this->newPositionCCXT->configureLoggingByContainer($container);
        $this->Provider = $container->get('provider.model');
        $this->newUser = $container->get('newUser.model');
        $this->ProfitSharingBalance = $container->get('profitSharingBalance.model');
    }

    /**
     * Get all keys from the queue db.
     * @return array
     */
    public function getAllKeysFromQueueDB()
    {
        return $this->RedisHandlerZignalyLocks->getAllKeys('lockingTurn_Position:*');
    }

    /**
     * Lock a resource that is not linked to a DB document.
     * @param string $resourceId
     * @param string $processName
     * @param string $processGroup
     * @param int $estimatedLockedTime
     * @return bool
     */
    public function lockNonDbResource(string $resourceId, string $processName, string $processGroup, int $estimatedLockedTime)
    {
        return $this->lockHard($processName, $resourceId, $processGroup, $estimatedLockedTime);
    }

    /**
     * Hard lock for the given position for the given process.
     *
     * @param string $positionId
     * @param string $processName
     * @param int $estimatedLockedTime
     * @param bool $withoutMongo
     * @return bool|BSONDocument
     */
    public function positionHardLock(string $positionId, string $processName, int $estimatedLockedTime = 60, bool $withoutMongo = false)
    {
        if (!$this->lockHard($processName, $positionId, 'position', $estimatedLockedTime)) {
            return false;
        }

        //Todo: the next if is temporal until all the locks are migrated to Redis.
        //Todo: actually, more than temporal, we could add the lockHardId to the position in the lockId, and add this parameter to
        //the updates that require the hard locks.
        if (!$withoutMongo) {
            $position = $this->newPositionCCXT->getAndLockPosition($positionId, $processName);
            if (!$position) {
                $positionIdString = is_object($positionId) ? $positionId->__toString() : $positionId;
                $this->Monolog->sendEntry('error', "Mongo was not able to lock the position $positionIdString");
                return false;
            }
        }

        return $this->newPositionCCXT->getPosition($positionId);
    }

    /**
     * Soft lock for the given position for the given process.
     *
     * @param string $positionId
     * @param string $processName
     * @return bool
     */
    public function positionSoftLock(string $positionId, string $processName)
    {
        return $this->softLock($processName, $positionId, 'position', 30);
    }

    /**
     * Soft lock for the given user for the given process.
     *
     * @param string $userId
     * @param string $processName
     * @param int $estimatedLockedTime
     * @return bool
     */
    public function userSoftLock(string $userId, string $processName, int $estimatedLockedTime = 60)
    {
        return $this->softLock($processName, $userId, 'user', $estimatedLockedTime);
    }

    /**
     * Hard lock for the given user and process.
     *
     * @param string $documentId
     * @param string $processName
     * @param int $estimatedLockedTime
     * @return array|bool|object|null
     */
    public function profitSharingBalanceHardLock(string $documentId, string $processName, int $estimatedLockedTime = 60)
    {
        if (!$this->lockHard($processName, $documentId, 'profitSharingBalance', $estimatedLockedTime)) {
            return false;
        } else {
            return $this->ProfitSharingBalance->getEntry($documentId);
        }
    }

    /**
     * Hard lock for the given provider and process.
     *
     * @param string $providerId
     * @param string $processName
     * @param int $estimatedLockedTime
     * @return bool|BSONDocument
     */
    public function providerHardLock(string $providerId, string $processName, int $estimatedLockedTime = 60)
    {
        if (!$this->lockHard($processName, $providerId, 'provider', $estimatedLockedTime)) {
            return false;
        } else {
            return $this->Provider->getProviderFromId($providerId);
        }
    }

    /**
     * Unlock the resource
     *
     * @param string $documentId
     * @param string $processName
     * @param string $lockType
     * @param string $collectionName
     */
    public function removeAnyLock(
        string $documentId,
        string $processName,
        string $lockType,
        string $collectionName
    ) :void {
        // lockType = all, hard, soft
        if (in_array($lockType, ['all', 'hard'])) {
            $this->releaseHardLock($processName, $documentId, $collectionName);
        }

        if (in_array($lockType, ['all', 'soft'])) {
            $this->releaseSoftLock($processName, $documentId, $collectionName);
        }
    }

    /**
     * Remove the specific lock for the given position and process.
     *
     * @param string $positionId
     * @param string $processName
     * @param string $lockType
     */
    public function removeLock(
        string $positionId,
        string $processName,
        string $lockType
    ) :void {
        $this->removeAnyLock($positionId, $processName, $lockType, 'position');
        $this->newPositionCCXT->unlockPositionFromProcess($positionId, $processName);
    }

    /**
     * Remove the given key from the redis db.
     * @param string $positionId
     * @return int
     */
    public function removeLockPositionEntryFromRedis(string $positionId)
    {
        $key = "lockingTurn_Position:$positionId";
        return $this->RedisHandlerZignalyLocks->removeKey($key);
    }

    /**
     * Hard lock for the given user and process.
     *
     * @param string $userId
     * @param string $processName
     * @param int $estimatedLockedTime
     * @return array|bool|object|null
     */
    public function userHardLock(string $userId, string $processName, int $estimatedLockedTime = 60)
    {
        if (!$this->lockHard($processName, $userId, 'user', $estimatedLockedTime)) {
            return false;
        } else {
            return $this->newUser->getUser($userId);
        }
    }

    /**
     * Do a soft lock for the given resource.
     * @param string $processName
     * @param string $documentId
     * @param string $collectionName
     * @param int $estimatedLockedTime
     * @return bool
     */
    private function softLock(string $processName, string $documentId, string $collectionName, int $estimatedLockedTime)
    {
        $key = "softLock_" . ucfirst(strtolower($collectionName)) . ':' . $documentId;
        $this->softLockId = md5(uniqid(rand() . microtime(true) . gethostname(), true));
        $value = "$processName:{$this->softLockId}";

        return $this->RedisHandlerZignalyLocks->addKey($key, $value, ['NX', 'EX' => $estimatedLockedTime]);
    }

    /**
     * Release the soft lock for the given resource.
     * @param string $processName
     * @param string $documentId
     * @param string $collectionName
     */
    private function releaseSoftLock(string $processName, string $documentId, string $collectionName) : void
    {
        $key = "softLock_" . ucfirst(strtolower($collectionName)) . ':' . $documentId;

        if ("$processName:{$this->softLockId}" === $this->RedisHandlerZignalyLocks->getKey($key)) {
            $this->RedisHandlerZignalyLocks->removeKey($key);
        }
    }

    /**
     * @param string $processName
     * @param string $documentId
     * @param string $collectionName
     */
    private function releaseHardLock(string $processName, string $documentId, string $collectionName) : void
    {
        $sortedSetKey = 'lockingTurn_' . ucfirst(strtolower($collectionName)) . ':' . $documentId;

        $key = "hardLock_" . ucfirst(strtolower($collectionName)) . ':' . $documentId;
        if ("$processName:{$this->hardLockId}" === $this->RedisHandlerZignalyLocks->getKey($key)) {
            $this->RedisHandlerZignalyLocks->removeKey($key);
        }

        $this->removeMembersFromQueue($sortedSetKey, $processName, true);
    }

    /**
     * Get the lock for a given resource.
     * @param string $processName
     * @param string $documentId
     * @param string $collectionName
     * @param int $estimatedLockedTime
     * @return bool
     */
    private function lockHard(string $processName, string $documentId, string $collectionName, int $estimatedLockedTime)
    {
        //First we need to get the resources directly, just in case it's free.
        $this->hardLockId = md5(uniqid(rand() . microtime(true) . gethostname(), true));
        $mainKey = "hardLock_" . ucfirst(strtolower($collectionName)) . ':' . $documentId;
        $mainValue = "$processName:{$this->hardLockId}";
        if ($this->RedisHandlerZignalyLocks->addKey($mainKey, $mainValue, ['NX', 'EX' => $estimatedLockedTime])) {
            return true;
        }

        //If resource is not free, then we check if this process is already asking from the resource from another source
        $key = 'lockingTurn_' . ucfirst(strtolower($collectionName)) . ':' . $documentId;
        if ($this->checkIfMemberExistsForAddingToTurnQueue($key, $processName)) {
            //$this->Monolog->sendEntry('debug', "A member already exists in the semaphore queue.");
            return false;
        }

        //We include the process in the turn queue.
        $canBeRemovedAt = time() + $estimatedLockedTime;
        $member = "$processName:{$this->hardLockId}:$estimatedLockedTime:$canBeRemovedAt";
        $this->RedisHandlerZignalyLocks->addSortedSet($key, time(), $member, true, 'NX');

        //We start checking if it's our turn.
        $count = 0;
        do {
            //This gives us the position
            $rank = $this->RedisHandlerZignalyLocks->zrank($key, $member);

            //If we get null, that means that somebody remove our process from the queue, so we stop asking for the turn.
            if (null === $rank) {
                $this->Monolog->sendEntry('debug', "Member removed from the semaphore queue");
                return false;
            }

            //If we get 0, it means that is our turn.
            if (0 === $rank) {
                //We extend the time at which our process can be removed from the queue.
                $newMember = $this->extendMemberCanBeRemovedAt($key, $member, $estimatedLockedTime);
                //If we don't get an updated member, something was wrong.
                if (!$newMember) {
                    $this->Monolog->sendEntry('debug', "The member didn't exist when trying to extend the expiration date");
                    return false;
                }
                //We again set the key, we are supposed to be the only ones trying to do it, but previous process could not be done yet.
                if ($this->setKeyForHardLock($mainKey, $mainValue, $estimatedLockedTime)) {
                    //We extend again the time at which our process can be removed from the queue.
                    $newMember = $this->extendMemberCanBeRemovedAt($key, $newMember, $estimatedLockedTime);
                    //And again, if we don't receive any updated, something was wrong.
                    if (!$newMember) {
                        $this->Monolog->sendEntry('debug', "The member didn't exist when trying to extend the expiration date just before granting the lock.");
                        return false;
                    }
                    //We remove any other member from the turn, that matches the same process buy is not us.
                    $this->removeMembersFromQueue($key, $processName, false);
                    return true;
                } else {
                    //We weren't able to get the final lock, so we remove ourselves from the queue turn and give up.
                    $this->removeMembersFromQueue($key, $processName, true);
                    //$this->Monolog->sendEntry('error', "Timeout trying to set the final key.");
                    return false;
                }
            } else {
                //Is not our turn yet, but we review if there are process above us that can be removed.
                $this->checkIfNextMemberShouldBeRemovedFromTurnQueue($key);
                //We wait a little bit and then try again.
                sleep(1);
                $count++;
            }
        } while ($count < 60);

        //At this point, we weren't able to get the turn, so we give up.
        //$this->Monolog->sendEntry('critical', "Timeout trying to get the lock.");
        return false;
    }

    /**
     * Extend the member expiration time
     * @param string $key
     * @param string $member
     * @param int $estimatedLockedTime
     * @return bool|string
     */
    private function extendMemberCanBeRemovedAt(string $key, string $member, int $estimatedLockedTime)
    {
        $currentScore = $this->RedisHandlerZignalyLocks->getScoringFromSortedSet($key, $member);
        if (null === $currentScore) {
            return false;
        }
        $newScore = $currentScore - 1;
        if ($newScore >= $currentScore) {
            return $member;
        }
        $memberData = explode(':', $member);
        $canBeRemovedAt = time() + $estimatedLockedTime;
        $newMember = "{$memberData[0]}:{$memberData[1]}:{$memberData[2]}:$canBeRemovedAt";

        if (1 === $this->RedisHandlerZignalyLocks->addSortedSet($key, $newScore, $newMember)) {
            $this->RedisHandlerZignalyLocks->removeMemberFromSortedSet($key, $member);
            return $newMember;
        }

        return $member;
    }
    /**
     * Remove members from the semaphore queue.
     * @param string $key
     * @param string $processName
     * @param bool $ownOnly
     */
    private function removeMembersFromQueue(string $key, string $processName, bool $ownOnly)
    {
        $set = $this->RedisHandlerZignalyLocks->getAllElementsFromSet($key, false);
        foreach ($set as $member) {
            list($process, $uniqueId, ) = explode(':', $member);
            if ($process === $processName) {
                if (($ownOnly && $uniqueId === $this->hardLockId) || (!$ownOnly && $uniqueId !== $this->hardLockId)) {
                    $this->RedisHandlerZignalyLocks->removeMemberFromSortedSet($key, $member);
                }
            }
        }
    }

    /**
     * Place the locking key or wait until the current one is expired.
     * @param string $key
     * @param string $value
     * @param int $estimatedLockedTime
     * @return bool
     */
    private function setKeyForHardLock(string $key, string $value, int $estimatedLockedTime)
    {
        $count = 0;
        do {
            if ($this->RedisHandlerZignalyLocks->addKey($key, $value, ['NX', 'EX' => $estimatedLockedTime])) {
                return true;
            }
            sleep(1);
            $count++;
        } while ($count < 60);

        return false;
    }

    /**
     * Check if the process is already in the queue, waiting its turn.
     *
     * @param string $key
     * @param string $processName
     * @return bool
     */
    private function checkIfMemberExistsForAddingToTurnQueue(string $key, string $processName)
    {
        $set = $this->RedisHandlerZignalyLocks->getAllElementsFromSet($key, true);

        $return = false;
        foreach ($set as $member => $score) {
            list($process, , , $canBeRemovedAt) = explode(':', $member);

            if (time() > $canBeRemovedAt) {
                $this->RedisHandlerZignalyLocks->removeMemberFromSortedSet($key, $member);
            } elseif ($process === $processName) {
                $return = true;
            }
        }

        return $return;
    }

    /**
     * Check if the next member in the sorted set has expired.
     * @param string $key
     */
    private function checkIfNextMemberShouldBeRemovedFromTurnQueue(string $key) : void
    {
        $set = $this->RedisHandlerZignalyLocks->getAllElementsFromSet($key, true);

        foreach ($set as $member => $score) {
            list(, $uniqueId, , $canBeRemovedAt) = explode(':', $member);
            if ($uniqueId === $this->hardLockId) {
                continue;
            }
            if (time() > $canBeRemovedAt) {
                $this->RedisHandlerZignalyLocks->removeMemberFromSortedSet($key, $member);
            }
        }
    }
}
