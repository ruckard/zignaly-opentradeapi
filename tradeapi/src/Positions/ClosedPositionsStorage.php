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


namespace Zignaly\Positions;

/**
 * Class ClosedPositionsStorage
 * @package Zignaly\Positions
 */
class ClosedPositionsStorage
{
    const POSITIONS_STORED = 500;

    /**
     * @var \RedisHandler
     */
    private $storage;

    /**
     * ClosedPositionsStorage constructor.
     */
    public function __construct(\RedisHandler $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Stores closed positions into Redis Sorted Set.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @param int $limit
     * @param string $scoreField
     * @return iterable
     */
    public function storePositions(
        string $userId,
        string $internalExchangeId,
        iterable $positions,
        string $scoreField
    ) {
        $key = $this->getClosedPositionsKey($userId, $internalExchangeId);
        $returnPositions = [];
        foreach ($positions as $position) {
            $returnPositions[json_encode($position)] = (string)$position[$scoreField];
        }
        $this->storage->delKey($key);
        $this->storage->addSortedSetPipeline($key, $returnPositions, 'GT');

        $this->limitSortedSet($key);
        return $returnPositions;
    }

    /**
     * Stores one closed position into Redis Sorted Set.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @param string $positionId
     * @param string $closingDate
     */
    public function storePosition(
        string $userId,
        string $internalExchangeId,
        array $position,
        string $closingDate
    ) {
        $key = $this->getClosedPositionsKey($userId, $internalExchangeId);
        $this->removePosition($userId, $internalExchangeId, $position[ClosedPositionsMap::FIELDS['positionId']]);
        $this->storage->addSortedSet(
            $key,
            $closingDate,
            json_encode($position),
            false,
            'GT'
        );

        $this->limitSortedSet($key);
    }

    /**
     * Returns all positions from Redis Sorted Set.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @return array
     */
    public function getPositions(string $userId, string $internalExchangeId): array
    {
        $key = $this->getClosedPositionsKey($userId, $internalExchangeId);
        $returnPositions = $this->storage->zRangeByScore($key, '-inf', '+inf');
        return $returnPositions;
    }

    /**
     * Remove duplicated positions from Redis Sorted Set.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @param string $positionId
     * @return void
     */
    private function removePosition(string $userId, string $internalExchangeId, string $positionId)
    {
        $positions = $this->getPositions($userId, $internalExchangeId);
        foreach ($positions as $position) {
            $pos = json_decode($position, true);
            if ($positionId === $pos[ClosedPositionsMap::FIELDS['positionId']]) {
                $key = $this->getClosedPositionsKey($userId, $internalExchangeId);
                $this->storage->zRem($key, $position);
            }
        }
    }

    /**
     * Removes older positions if the sorted set grows over POSITIONS_STORED.
     * 
     * @param string $key
     */
    private function limitSortedSet(string $key)
    {
        $deleteCount = $this->storage->zCard($key) - static::POSITIONS_STORED;
        if ($deleteCount > 0) {
            $this->storage->popManyFromSet($key, $deleteCount);
        }
    }

    private function getClosedPositionsKey(string $userId, string $internalExchangeId): string
    {
        return sprintf("CLOSED_POSITIONS:%s:%s", $userId, $internalExchangeId);
    }
}