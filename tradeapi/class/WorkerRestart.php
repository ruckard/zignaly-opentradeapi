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


use Zignaly\db\model\WorkerRestartModel;

class WorkerRestart
{
    private $collectionName = 'workerRestart';
    private $mongoDBLink;

    function __construct()
    {
        global $mongoDBLink;

        $this->mongoDBLink = $mongoDBLink;
    }

    public function workerNeedsRestart($process)
    {
        $find = [
            'process' => $process,
        ];

        $workerRestart = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);

        // Create worker restart entry when not exists.
        if (!$workerRestart) {
           $workerRestartModel = new WorkerRestartModel($this->mongoDBLink);
           $workerRestartModel->restartWorkerSetup($process);
           $workerRestart = $this->mongoDBLink->selectCollection($this->collectionName)->findOne($find);
        }

        return $workerRestart->lastRestart->__toString() / 1000;
    }

    /**
     * Prepare all process for restarting, if process is not false, then the given process only will be prepared.
     *
     * @param bool|string $process
     * @return \MongoDB\UpdateResult
     */
    public function prepareAllProcessedForRestarting($process = false)
    {
        if ($process) {
            $find = [
                'process' => $process,
            ];
        } else {
            $find = [];
        }

        $set = [
            '$set' => [
                'lastRestart' => new \MongoDB\BSON\UTCDateTime(),
            ]
        ];

        return $this->mongoDBLink->selectCollection($this->collectionName)->updateMany($find, $set);
    }
}
