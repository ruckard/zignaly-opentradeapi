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

class RestartWorker
{
    private $WorkerRestart;

    /**
     * Max memory limit allowed to the monitored process.
     */
    const MAX_MEMORY_LIMIT_MB = 500;

    /**
     * Max process cycles that trigger clean process restart.
     */
    const MAX_CYCLES_LIMIT = 400;

    /**
     * Logging instance.
     *
     * @var \Monolog
     */
    private $Monolog;

    /**
     * Check calls counter.
     *
     * Track the number of calls performed to check process status to determine total cycles.
     *
     * @var int
     */
    private $checkCount = 0;

    /**
     * Monitored process name.
     *
     * @var string
     */
    private $process;

    /**
     * Number of processor cores in this system.
     *
     * @var int
     */
    private $processorCores;

    /**
     * Monitored process unix time start.
     *
     * @var int
     */
    private $startTime;

    function __construct()
    {
        global $WorkerRestart;

        // TODO: Clean global dependency injection when DI injection is properly tested.
        $this->WorkerRestart = $WorkerRestart;
        // When possible use DI container to resolve dependency.
        $container = DIContainer::getContainer();
        if ($container->has('workerRestart')) {
            $this->WorkerRestart = $WorkerRestart;
        }

        $this->processorCores = $this->getProcessorCoresNumber();
    }

    /**
     * Increase counter on process cycle.
     *
     * In order to guarantee correct control of memory usage the cycle counter should represent one unit of
     * task processing so this counter cannot be incremented automatically from process main loop.
     */
    public function countCycle()
    {
        $this->checkCount++;
    }

    public function checkProcessStatus($process, $scriptStartTime, & $Monolog, $maxMemLimit = false)
    {
        $this->startTime = $scriptStartTime;
        $this->process = $process;
        $this->Monolog = $Monolog;

        if ($this->isMaxCyclesReached()) {
            exit();
        }

        /*$lastRestart = $this->WorkerRestart->workerNeedsRestart($process);
        if ($scriptStartTime < $lastRestart) {
            $this->Monolog->sendEntry('debug', "Restarting processes $process");
            sleep(rand(0, 60));
            exit();
        }*/

        if (!$maxMemLimit) {
            $maxMemLimit = self::MAX_MEMORY_LIMIT_MB;
        }

        if ($this->isMemoryExhausted($maxMemLimit)) {
            exit();
        }

        /*if ($this->isSystemOverLoaded()) {
            $this->Monolog->sendEntry('error', "Restarting processes $process because current cpu usage is over limit");

            exit();
        }*/

        return false;
    }

    /**
     * Check if the total load is bigger than 60% total cpu power.
     *
     * @return bool
     */
    private function isSystemOverLoaded()
    {
        $load = sys_getloadavg();
        $load5m = $load[0];
        $loadPerCPU = $load5m / $this->processorCores;

        return $loadPerCPU > 0.60;
    }

    /**
     * Return the number of processor cores in the system running the script.
     *
     * @return int
     */
    private function getProcessorCoresNumber()
    {
        $command = "grep -c processor /proc/cpuinfo";

        return (int)shell_exec($command);
    }

    /**
     * Validate if max cycles limit is reached.
     *
     * This control ensures that process are cleanly restarted after N cycles to avoid memory exhaustion issues,
     * currently Mongo DB server_supports_feature function call eats memory for each process cycle so assuming
     * that troubleshoot third party library is a complex task we decided a short term quick solution.
     */
    private function isMaxCyclesReached()
    {
        if ($this->checkCount >= self::MAX_CYCLES_LIMIT) {
            return true;
        }
    }

    /**
     * Check if monitored process has exceeded max allowed memory limit.
     *
     * TODO: Investigate why memory exhaustion check is consuming more memory than expected, issue that noted
     * by Tole when tried to activate in production clean restart based on memory limit.
     *
     * @return bool TRUE if memory is exhausted, FALSE otherwise.
     */
    private function isMemoryExhausted(int $maxMemLimit): bool
    {
        $memoryUsageMb = round(memory_get_usage(true) / 1048576, 2);
        $elapsedHours = (time() - $this->startTime) / 3600;

        /*$this->Monolog->sendEntry(
            'debug',
            sprintf('Process current memory usage %d MB.', $memoryUsageMb)
        );*/

        if ($memoryUsageMb >= $maxMemLimit) {
            $this->Monolog->sendEntry(
                'critical',
                sprintf(
                    '%s process clean exit: exhausted memory limit (%d) - %dMB - %d cycles - %d elapsed hours.',
                    $this->process,
                    $maxMemLimit,
                    $memoryUsageMb,
                    $this->checkCount,
                    $elapsedHours
                )
            );

            return true;
        }

        return false;
    }

    public function prepareAllProcessedForRestarting($process = false)
    {
        return $this->WorkerRestart->prepareAllProcessedForRestarting($process);
    }
}