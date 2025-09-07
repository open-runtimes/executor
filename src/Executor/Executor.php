<?php

namespace OpenRuntimes\Executor;

use OpenRuntimes\Executor\Runner\Adapter as Runner;
use Utopia\Logger\Log;

class Executor
{
    public function __construct(
        private readonly Runner $runner,
    ) {
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
