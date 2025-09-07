<?php

namespace OpenRuntimes\Executor;

use OpenRuntimes\Executor\Runner\Docker as Runner;
use Utopia\Orchestration\Orchestration;
use Utopia\Logger\Log;

class Executor
{
    private readonly Runner $runner;

    /**
     * @param Orchestration $orchestration
     * @param string[] $networks
     */
    public function __construct(
        private readonly Orchestration $orchestration,
        private readonly array $networks,
    ) {
        $this->runner = new Runner($this->orchestration, $this->networks);
    }

    public function createExecution(
        string $runtimeId,
        ?string $payload,
        string $path,
        string $method,
        mixed $headers,
        int $timeout,
        string $image,
        string $source,
        string $entrypoint,
        mixed $variables,
        float $cpus,
        int $memory,
        string $version,
        string $runtimeEntrypoint,
        bool $logging,
        string $restartPolicy,
        Log $log,
        string $region = '',
    ): mixed {
        return $this->runner->createExecution(
            $runtimeId,
            $payload,
            $path,
            $method,
            $headers,
            $timeout,
            $image,
            $source,
            $entrypoint,
            $variables,
            $cpus,
            $memory,
            $version,
            $runtimeEntrypoint,
            $logging,
            $restartPolicy,
            $log,
            $region,
        );
    }
}
