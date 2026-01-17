<?php

namespace OpenRuntimes\Executor\Runner;

use Utopia\Http\Response;

abstract class Adapter
{
    abstract public function getLogs(string $runtimeId, int $timeout, Response $response): void;

    abstract public function executeCommand(string $runtimeId, string $command, int $timeout): string;

    /**
     * @param string[] $variables
     */
    abstract public function createRuntime(
        string $runtimeId,
        string $secret,
        string $image,
        string $entrypoint,
        string $source,
        string $destination,
        array $variables,
        string $runtimeEntrypoint,
        string $command,
        int $timeout,
        bool $remove,
        float $cpus,
        int $memory,
        string $version,
        string $restartPolicy,
        string $region = '',
    ): mixed;

    abstract public function deleteRuntime(string $runtimeId): void;

    abstract public function createExecution(
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
        string $region = '',
    ): mixed;

    abstract public function getRuntimes(): mixed;

    abstract public function getRuntime(string $name): mixed;
}
