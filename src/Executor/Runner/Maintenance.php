<?php

namespace OpenRuntimes\Executor\Runner;

use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use Swoole\Timer;
use Utopia\Console;
use Utopia\Orchestration\Orchestration;
use Utopia\Storage\Device\Local;
use Utopia\System\System;

use function Swoole\Coroutine\batch;

/**
 * Handles periodic cleanup of inactive runtimes and orphaned temp directories.
 */
class Maintenance
{
    private int|false $timerId = false;

    public function __construct(
        private readonly Orchestration $orchestration,
        private readonly Runtimes $runtimes
    ) {
    }

    /**
     * Starts the maintenance loop. No-op if already running.
     */
    public function start(int $intervalSeconds, int $inactiveSeconds): void
    {
        if ($this->timerId !== false) {
            return;
        }

        $intervalMs = $intervalSeconds * 1000;
        $this->timerId = Timer::tick($intervalMs, fn () => $this->tick($inactiveSeconds));
        Console::info(sprintf('[Maintenance] Started task on interval %d seconds.', $intervalSeconds));
    }

    /**
     * Stops the maintenance loop. No-op if already stopped.
     */
    public function stop(): void
    {
        if ($this->timerId === false) {
            return;
        }

        Timer::clear($this->timerId);
        $this->timerId = false;
        Console::info("[Maintenance] Stopped task.");
    }

    /**
     * Removes runtimes inactive beyond the threshold and cleans up temporary files.
     */
    private function tick(int $inactiveSeconds): void
    {
        Console::info(sprintf('[Maintenance] Running task with threshold %d seconds.', $inactiveSeconds));

        $threshold = \time() - $inactiveSeconds;
        $candidates = array_filter(
            $this->runtimes->list(),
            fn (\OpenRuntimes\Executor\Runner\Runtime $runtime): bool => $runtime->updated < $threshold
        );

        // Remove from in-memory state before removing the container.
        // Ensures availability, otherwise we would route requests to terminating runtimes.
        $keys = array_keys($candidates);
        foreach ($keys as $key) {
            $this->runtimes->remove($key);
        }

        // Then, remove forcefully terminate the associated running container.
        $jobs = array_map(
            fn (\OpenRuntimes\Executor\Runner\Runtime $candidate): \Closure => fn (): bool => $this->orchestration->remove($candidate->name, force: true),
            $candidates
        );
        $results = batch($jobs);
        $removed = \count(array_filter($results));

        Console::info(sprintf('[Maintenance] Removed %d/', $removed) . \count($candidates) . " inactive runtimes.");

        $this->cleanupTmp();
    }

    private function cleanupTmp(): void
    {
        $localDevice = new Local();
        $tmpPath = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
        $prefix = $tmpPath . System::getHostname() . '-';

        foreach ($localDevice->getFiles($tmpPath) as $entry) {
            if (!\str_starts_with($entry, $prefix)) {
                continue;
            }

            $runtimeName = substr($entry, \strlen($tmpPath));
            if ($this->runtimes->exists($runtimeName)) {
                continue;
            }

            if ($localDevice->deletePath($entry)) {
                Console::info(sprintf('[Maintenance] Removed %s.', $entry));
            }
        }
    }
}
