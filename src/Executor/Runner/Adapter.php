<?php

namespace OpenRuntimes\Executor\Runner;

use Utopia\Http\Response;

abstract class Adapter
{
    /**
     * @param string $runtimeId
     * @param int $timeout
     * @param Response $response
     * @return void
     */
    abstract public function getLogs(string $runtimeId, int $timeout, Response $response): void;

    /**
     * @param string $runtimeId
     * @param string $command
     * @param int $timeout
     * @return string
     */
    abstract public function executeCommand(string $runtimeId, string $command, int $timeout): string;

    /**
     * @param string $runtimeId
     * @param string $secret
     * @param string $image
     * @param string $entrypoint
     * @param string $source
     * @param string $destination
     * @param string[] $variables
     * @param string $runtimeEntrypoint
     * @param string $command
     * @param int $timeout
     * @param bool $remove
     * @param float $cpus
     * @param int $memory
     * @param string $version
     * @param string $restartPolicy
     * @return mixed
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

    /**
     * @param string $runtimeId
     * @return void
     */
    abstract public function deleteRuntime(string $runtimeId): void;

    /**
     * @param string $runtimeId
     * @param string|null $payload
     * @param string $path
     * @param string $method
     * @param mixed $headers
     * @param int $timeout
     * @param string $image
     * @param string $source
     * @param string $entrypoint
     * @param mixed $variables
     * @param float $cpus
     * @param int $memory
     * @param string $version
     * @param string $runtimeEntrypoint
     * @param bool $logging
     * @param string $restartPolicy
     * @return mixed
     */
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
