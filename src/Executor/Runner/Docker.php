<?php

namespace OpenRuntimes\Executor\Runner;

use OpenRuntimes\Executor\Logs;
use OpenRuntimes\Executor\Exception as ExecutorException;
use OpenRuntimes\Executor\RegularFile;
use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use OpenRuntimes\Executor\StorageFactory;
use OpenRuntimes\Executor\Validator\TCP;
use Swoole\Timer;
use Throwable;
use Utopia\Console;
use Utopia\Http\Response;
use Utopia\Orchestration\Orchestration;
use Utopia\Orchestration\Exception\Timeout as TimeoutException;
use Utopia\Orchestration\Exception\Orchestration as OrchestrationException;
use Utopia\Storage\Device\Local;
use Utopia\System\System;

class Docker extends Adapter
{
    /**
     * @param string[] $networks
     */
    public function __construct(
        private readonly Orchestration $orchestration,
        private readonly Runtimes $runtimes,
        private readonly array $networks
    ) {
    }

    public function getLogs(string $runtimeId, int $timeout, Response $response): void
    {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $tmpFolder = sprintf('tmp/%s/', $runtimeName);
        $tmpLogging = sprintf('/%slogging', $tmpFolder); // Build logs

        // TODO: Combine 3 checks below into one

        // Wait for runtime
        for ($i = 0; $i < 10; $i++) {
            $output = '';
            $stderr = '';
            $code = Console::execute('docker container inspect ' . \escapeshellarg($runtimeName), '', $output, $stderr);
            if ($code === 0) {
                break;
            }

            if ($i === 9) {
                throw new \Exception('Runtime not ready. Container not found.');
            }

            \usleep(500000); // 0.5s
        }

        // Wait for state
        $version = null;
        $checkStart = \microtime(true);
        while (true) {
            if (\microtime(true) - $checkStart >= 10) { // Enforced timeout of 10s
                throw new ExecutorException(ExecutorException::RUNTIME_TIMEOUT);
            }

            $runtime = $this->runtimes->get($runtimeName);
            if ($runtime instanceof \OpenRuntimes\Executor\Runner\Runtime) {
                $version = $runtime->version;
                break;
            }

            \usleep(500000); // 0.5s
        }

        if ($version === 'v2') {
            return;
        }

        // Wait for logging files
        $checkStart = \microtime(true);
        while (true) {
            if (\microtime(true) - $checkStart >= $timeout) {
                throw new ExecutorException(ExecutorException::LOGS_TIMEOUT);
            }

            if (\file_exists($tmpLogging . '/logs.txt') && \file_exists($tmpLogging . '/timings.txt')) {
                // The build container writes these files, so they are read through RegularFile.
                $timings = RegularFile::read($tmpLogging . '/timings.txt');
                if ($timings !== '') {
                    break;
                }
            }

            // Ensure runtime is still present
            $runtime = $this->runtimes->get($runtimeName);
            if (!$runtime instanceof \OpenRuntimes\Executor\Runner\Runtime) {
                return;
            }

            \usleep(500000); // 0.5s
        }

        /**
         * @var mixed $logsChunk
         */
        $logsChunk = '';

        /**
         * @var mixed $logsProcess
         */
        $logsProcess = null;

        $streamInterval = 1000; // 1 second
        $activeRuntimes = $this->runtimes;
        $timerId = Timer::tick($streamInterval, function () use (&$logsProcess, &$logsChunk, $response, $activeRuntimes, $runtimeName): void {
            $runtime = $activeRuntimes->get($runtimeName);
            if (!$runtime instanceof \OpenRuntimes\Executor\Runner\Runtime) {
                if (!empty($logsChunk)) {
                    $response->write($logsChunk);
                    $logsChunk = '';
                }

                if (!empty($logsProcess)) {
                    \proc_terminate($logsProcess, 9);
                }

                return;
            }

            if ($runtime->initialised === 1) {
                if (!empty($logsChunk)) {
                    $write = $response->write($logsChunk);
                    $logsChunk = '';
                }

                \proc_terminate($logsProcess, 9);
                return;
            }

            if (empty($logsChunk)) {
                return;
            }

            $write = $response->write($logsChunk);
            $logsChunk = '';

            if (!$write && !empty($logsProcess)) {
                \proc_terminate($logsProcess, 9);
            }
        });

        $offset = 0; // Current offset from timing for reading logs content
        $tempLogsContent = RegularFile::read($tmpLogging . '/logs.txt');
        $introOffset = Logs::getLogOffset($tempLogsContent);

        $datetime = new \DateTime("now", new \DateTimeZone("UTC")); // Date used for tracking absolute log timing

        $output = ''; // Unused, just a refference for stdout
        $stderr = '';
        Console::execute('tail -F ' . $tmpLogging . '/timings.txt', '', $output, $stderr, $timeout, function (string $timingChunk, mixed $process) use ($tmpLogging, &$logsChunk, &$logsProcess, &$datetime, &$offset, $introOffset): void {
            $logsProcess = $process;

            $logsPath = $tmpLogging . '/logs.txt';
            if (!\file_exists($logsPath)) {
                if (!empty($logsProcess)) {
                    \proc_terminate($logsProcess, 9);
                }

                return;
            }

            $parts = Logs::parseTiming($timingChunk, $datetime);

            foreach ($parts as $part) {
                $timestamp = $part['timestamp'] ?? '';
                $length = \intval($part['length'] ?? '0');

                $logContent = RegularFile::read($logsPath, $introOffset + $offset, \abs($length));

                $logContent = \str_replace("\n", "\\n", $logContent);

                $output = $timestamp . " " . $logContent . "\n";

                $logsChunk .= $output;
                $offset += $length;
            }
        });

        if (!$timerId) {
            throw new \Exception('Failed to create timer');
        }

        Timer::clear($timerId);
    }

    public function executeCommand(string $runtimeId, string $command, int $timeout): string
    {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        if (!$this->runtimes->exists($runtimeName)) {
            throw new ExecutorException(ExecutorException::RUNTIME_NOT_FOUND);
        }

        $commands = [
            'bash',
            '-c',
            $command
        ];

        $output = '';

        try {
            $this->orchestration->execute($runtimeName, $commands, $output, [], $timeout);
            return $output;
        } catch (TimeoutException $e) {
            throw new ExecutorException(ExecutorException::COMMAND_TIMEOUT, previous: $e);
        } catch (OrchestrationException $e) {
            throw new ExecutorException(ExecutorException::COMMAND_FAILED, previous: $e);
        }
    }

    /**
     * @param string[] $variables
     */
    public function createRuntime(
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
        string $cacheKey = '',
        string $region = '',
    ): mixed {
        $runtimeName = System::getHostname() . '-' . $runtimeId;
        $runtimeHostname = \bin2hex(\random_bytes(16));

        if ($this->runtimes->exists($runtimeName)) {
            $existingRuntime = $this->runtimes->get($runtimeName);
            if ($existingRuntime instanceof \OpenRuntimes\Executor\Runner\Runtime && $existingRuntime->status === 'pending') {
                throw new ExecutorException(ExecutorException::RUNTIME_CONFLICT, 'A runtime with the same ID is already being created. Attempt a execution soon.');
            }

            throw new ExecutorException(ExecutorException::RUNTIME_CONFLICT);
        }

        /** @var array<string, mixed> $container */
        $container = [];
        $output = [];
        $cacheWarnings = [];
        $startTime = \microtime(true);

        $runtime = new Runtime(
            version: $version,
            created: $startTime,
            updated: $startTime,
            name: $runtimeName,
            hostname: $runtimeHostname,
            status: 'pending',
            key: $secret,
            listening: 0,
            image: $image,
            initialised: 0,
        );
        $this->runtimes->set($runtimeName, $runtime);

        /**
         * Temporary file paths in the executor
         */
        $buildFile = "code.tar.gz";

        // Known archive formats keep their identity inside the runtime;
        // anything else keeps the legacy code.tar.gz name, which client
        // commands reference regardless of the real format (zip sources,
        // build artifacts stored as <uniqid>.gz).
        $sourceFile = match (\pathinfo($source, PATHINFO_EXTENSION)) {
            'tar' => 'code.tar',
            'sqfs' => 'code.sqfs',
            'erofs' => 'code.erofs',
            default => 'code.tar.gz',
        };

        // The source dir is mounted at /tmp inside the runtime, so this is
        // where the start helper (extract.sh) finds the archive.
        if ($source !== '' && $source !== '0' && $version !== 'v2') {
            $variables['OPEN_RUNTIMES_CODE_PATH'] = '/tmp/' . $sourceFile;
        }

        $tmpFolder = sprintf('tmp/%s/', $runtimeName);
        $tmpSource = sprintf('/%ssrc/%s', $tmpFolder, $sourceFile);
        $tmpBuild = sprintf('/%sbuilds/%s', $tmpFolder, $buildFile);
        $tmpCache = sprintf('/%scache', $tmpFolder);
        $tmpLogging = sprintf('/%slogging', $tmpFolder); // Build logs
        $tmpLogs = sprintf('/%slogs', $tmpFolder); // Runtime logs

        $sourceDevice = StorageFactory::getDevice("/", System::getEnv('OPR_EXECUTOR_CONNECTION_STORAGE'));
        $localDevice = new Local();

        try {
            /**
             * Copy code files from source to a temporary location on the executor
             */
            if ($source !== '' && $source !== '0' && !$sourceDevice->copy($source, $tmpSource, $localDevice)) {
                throw new \Exception('Failed to copy source code to temporary directory');
                ;
            }

            /**
             * Create the mount folder
             */
            if (!$localDevice->createDirectory(\dirname($tmpBuild))) {
                throw new \Exception("Failed to create temporary directory");
            }

            $this->orchestration
                ->setCpus($cpus)
                ->setMemory($memory);

            if ($runtimeEntrypoint === '' || $runtimeEntrypoint === '0') {
                $runtimeEntrypointCommands = $version === 'v2' && ($command === '' || $command === '0') ? [] : ['tail', '-f', '/dev/null'];
            } else {
                $runtimeEntrypointCommands = ['bash', '-c', $runtimeEntrypoint];
            }

            $codeMountPath = $version === 'v2' ? '/usr/code' : '/mnt/code';
            $workdir = $version === 'v2' ? '/usr/code' : '';

            $network = $this->networks[array_rand($this->networks)];

            $volumes = [
                \dirname($tmpSource) . ':/tmp:rw',
                \dirname($tmpBuild) . ':' . $codeMountPath . ':rw',
            ];

            $cacheEnabled = $cacheKey !== '' && $command !== '' && $command !== '0';
            if ($cacheEnabled) {
                $this->validateBuildCacheKey($cacheKey);

                try {
                    $this->restoreBuildCacheArtifact($cacheKey, $tmpCache, $localDevice);
                } catch (Throwable $err) {
                    $cacheWarnings[] = $this->getBuildCacheWarning('Failed to restore cache artifact: ' . $err->getMessage());

                    if (!$localDevice->exists($tmpCache)) {
                        $localDevice->createDirectory($tmpCache);
                    }
                }

                $volumes[] = $tmpCache . ':/cache:rw';
            }

            if ($version === 'v5') {
                $volumes[] = \dirname($tmpLogs . '/logs') . ':/mnt/logs:rw';
                $volumes[] = \dirname($tmpLogging . '/logging') . ':/tmp/logging:rw';
            }

            /** Keep the container alive if we have commands to be executed */
            $containerId = $this->orchestration->run(
                image: $image,
                name: $runtimeName,
                command: $runtimeEntrypointCommands,
                workdir: $workdir,
                volumes: $volumes,
                vars: $variables,
                labels: [
                    'openruntimes-executor' => System::getHostname(),
                    'openruntimes-runtime-id' => $runtimeId
                ],
                hostname: $runtimeHostname,
                network: $network,
                restart: $restartPolicy
            );

            if ($containerId === '' || $containerId === '0') {
                throw new \Exception('Failed to create runtime');
            }

            /**
             * Execute any commands if they were provided
             */
            if ($command !== '' && $command !== '0') {
                $stdout = '';

                try {
                    $status = $this->orchestration->execute(
                        name: $runtimeName,
                        command: $this->getBuildCommands($command, $version),
                        output: $stdout,
                        timeout: $timeout
                    );

                    if (!$status) {
                        $message = \trim(\mb_strcut($stdout, 0, MAX_BUILD_LOG_SIZE));

                        throw new ExecutorException(
                            ExecutorException::BUILD_FAILED,
                            $message === '' ? 'Build command failed.' : $message
                        );
                    }

                    if ($version === 'v2') {
                        $stdout = \mb_substr($stdout ?: 'Runtime created successfully!', -MAX_BUILD_LOG_SIZE); // Limit to 1MB
                        $output[] = [
                            'timestamp' => Logs::getTimestamp(),
                            'content' => $stdout
                        ];
                    } elseif (!$cacheEnabled) {
                        $output = Logs::get($runtimeName);
                    }
                } catch (ExecutorException $err) {
                    throw $err;
                } catch (OrchestrationException $err) {
                    $message = \trim(\mb_strcut($stdout, 0, MAX_BUILD_LOG_SIZE));

                    throw new ExecutorException(
                        ExecutorException::BUILD_FAILED,
                        $message === '' ? 'Build command failed.' : $message,
                        previous: $err
                    );
                } catch (Throwable $err) {
                    throw new ExecutorException(ExecutorException::RUNTIME_FAILED, $err->getMessage(), null, $err);
                }

                if ($cacheEnabled) {
                    try {
                        $this->storeBuildCacheArtifact($cacheKey, $tmpCache, $localDevice);
                    } catch (Throwable $err) {
                        $cacheWarnings[] = $this->getBuildCacheWarning('Failed to store cache artifact: ' . $err->getMessage());
                    }

                    if ($version === 'v2') {
                        $output = \array_merge($output, $cacheWarnings);
                    } else {
                        $output = \array_merge($cacheWarnings, Logs::get($runtimeName));
                    }
                }
            }

            /**
             * Move built code to expected build directory
             */
            if ($destination !== '' && $destination !== '0') {
                // The build container can write this path. Following a symlink there would upload the
                // executor's own files (its environment) as the build, for the owner to download.
                // The upload reads a copy in /tmp/<runtime>, which no container mounts.
                $snapshot = \dirname($tmpBuild, 2) . '/output-' . \basename($tmpBuild);
                try {
                    $size = RegularFile::copy($tmpBuild, $snapshot);
                    if ($size === null) {
                        throw new \Exception('Something went wrong when starting runtime.');
                    }

                    $container['size'] = $size;

                    $destinationDevice = StorageFactory::getDevice($destination, System::getEnv('OPR_EXECUTOR_CONNECTION_STORAGE'));
                    $path = $destinationDevice->getPath(\uniqid() . '.' . \pathinfo($tmpBuild, PATHINFO_EXTENSION));

                    if (!$localDevice->copy($snapshot, $path, $destinationDevice)) {
                        throw new \Exception('Failed to move built code to storage');
                    };
                } finally {
                    // A runtime kept after its build keeps /tmp/<runtime>, so the copy is removed here.
                    @\unlink($snapshot);
                }

                $container['path'] = $path;
            }

            $endTime = \microtime(true);
            $duration = $endTime - $startTime;

            $container = array_merge($container, [
                'output' => $output,
                'startTime' => $startTime,
                'duration' => $duration,
            ]);

            $runtime = $this->runtimes->get($runtimeName);
            if ($runtime instanceof \OpenRuntimes\Executor\Runner\Runtime) {
                $runtime->updated = \microtime(true);
                $runtime->status = 'Up ' . \round($duration, 2) . 's';
                $runtime->initialised = 1;

                $this->runtimes->set($runtimeName, $runtime);
            }
        } catch (Throwable $throwable) {
            if ($version === 'v2') {
                $message = $throwable->getMessage();
                try {
                    $logs = '';
                    $status = $this->orchestration->execute(
                        name: $runtimeName,
                        command: ['sh', '-c', 'cat /var/tmp/logs.txt'],
                        output: $logs,
                        timeout: 15
                    );

                    if ($logs !== '' && $logs !== '0') {
                        $message = $logs;
                    }

                    $message = \mb_substr($message, -MAX_BUILD_LOG_SIZE); // Limit to 1MB
                } catch (Throwable) {
                    // Ignore, use fallback error message
                }

                $output = [[
                    'timestamp' => Logs::getTimestamp(),
                    'content' => $message
                ]];
            } else {
                $output = Logs::get($runtimeName);
                $output = $output !== [] ? $output : [[
                    'timestamp' => Logs::getTimestamp(),
                    'content' => $throwable->getMessage()
                ]];
            }

            if ($cacheWarnings !== []) {
                $output = \array_merge($cacheWarnings, $output);
            }

            if ($remove) {
                \sleep(2); // Allow time to read logs
            }

            // Silently try to kill container
            try {
                $this->orchestration->remove($runtimeName, true);
            } catch (Throwable) {
            }

            $localDevice->deletePath($tmpFolder);
            $this->runtimes->remove($runtimeName);

            if ($throwable instanceof ExecutorException) {
                throw $throwable;
            }

            $message = '';
            foreach ($output as $chunk) {
                $message .= $chunk['content'];
            }

            throw new \Exception($message, $throwable->getCode() ?: 500, $throwable);
        }

        // Container cleanup
        if ($remove) {
            \sleep(2); // Allow time to read logs

            // Silently try to kill container
            try {
                $this->orchestration->remove($runtimeName, true);
            } catch (Throwable) {
            }

            $localDevice->deletePath($tmpFolder);
            $this->runtimes->remove($runtimeName);
        }

        // Remove weird symbol characters (for example from Next.js)
        foreach ($container['output'] as &$chunk) {
            $chunk['content'] = \mb_convert_encoding($chunk['content'] ?? '', 'UTF-8', 'UTF-8');
        }

        return $container;
    }

    /**
     * @return string[]
     */
    private function getBuildCommands(string $command, string $version): array
    {
        if ($version === 'v2') {
            return [
                'sh',
                '-c',
                'touch /var/tmp/logs.txt && (' . $command . ') >> /var/tmp/logs.txt 2>&1; status=$?; cat /var/tmp/logs.txt; if [ $status -ne 0 ]; then echo "Build command exited with code $status."; fi; exit $status'
            ];
        }

        return [
            'bash',
            '-c',
            'mkdir -p /tmp/logging && touch /tmp/logging/timings.txt && touch /tmp/logging/logs.txt && script --log-out /tmp/logging/logs.txt --flush --log-timing /tmp/logging/timings.txt --return --quiet --command "' . \str_replace('"', '\"', $command) . '"; status=$?; if [ $status -ne 0 ]; then echo "Build command exited with code $status."; fi; exit $status'
        ];
    }

    /**
     * @return array{timestamp: string, content: string}
     */
    private function getBuildCacheWarning(string $message): array
    {
        return [
            'timestamp' => Logs::getTimestamp(),
            'content' => '[build cache] Warning: ' . $message . "\n"
        ];
    }

    private function validateBuildCacheKey(string $cacheKey): void
    {
        if (
            \str_contains($cacheKey, "\0") ||
            \str_starts_with($cacheKey, '/') ||
            \str_contains($cacheKey, '\\') ||
            \preg_match('#(^|/)\.\.(?:/|$)#', $cacheKey) === 1
        ) {
            throw new \Exception('Invalid build cache key', 400);
        }
    }

    private function restoreBuildCacheArtifact(string $cacheKey, string $tmpCache, Local $localDevice): void
    {
        if (!$localDevice->createDirectory($tmpCache)) {
            throw new \Exception('Failed to create build cache directory');
        }

        $cacheDevice = $this->getBuildCacheDevice();
        $artifact = $this->getBuildCacheArtifactPath($cacheDevice, $cacheKey);

        if (!$cacheDevice->exists($artifact)) {
            return;
        }

        if (!$cacheDevice->copy($artifact, $tmpCache . '/stores.sqfs', $localDevice)) {
            throw new \Exception('Failed to restore build cache artifact');
        }
    }

    private function storeBuildCacheArtifact(string $cacheKey, string $tmpCache, Local $localDevice): void
    {
        $artifact = $tmpCache . '/stores.sqfs';

        // /cache is mounted into the build container, so anything but a regular file is skipped, and
        // the upload reads a copy in /tmp/<runtime>, which no container mounts.
        $snapshot = \dirname($tmpCache) . '/cache-stores.sqfs';
        try {
            if (RegularFile::copy($artifact, $snapshot) === null) {
                return;
            }

            $cacheDevice = $this->getBuildCacheDevice();
            $destinationArtifact = $this->getBuildCacheArtifactPath($cacheDevice, $cacheKey);

            if (!$localDevice->copy($snapshot, $destinationArtifact, $cacheDevice)) {
                throw new \Exception('Failed to store build cache artifact');
            }
        } finally {
            @\unlink($snapshot);
        }
    }

    private function getBuildCacheDevice(): \Utopia\Storage\Device
    {
        $connection = System::getEnv('OPR_EXECUTOR_CONNECTION_BUILD_CACHE_STORAGE', '');
        if ($connection === '' || $connection === '0') {
            $connection = System::getEnv('OPR_EXECUTOR_CONNECTION_STORAGE');
        }

        return StorageFactory::getDevice('/build-cache', $connection);
    }

    private function getBuildCacheArtifactPath(\Utopia\Storage\Device $device, string $cacheKey): string
    {
        return $device->getPath($cacheKey . '/lz4-b1M/stores.sqfs');
    }

    public function deleteRuntime(string $runtimeId): void
    {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        if (!$this->runtimes->exists($runtimeName)) {
            throw new ExecutorException(ExecutorException::RUNTIME_NOT_FOUND);
        }

        $this->orchestration->remove($runtimeName, true);
        $this->runtimes->remove($runtimeName);
    }

    /**
     * @throws ExecutorException
     */
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
        string $region = '',
        ?callable $headersCallback = null,
        ?callable $bodyCallback = null,
    ): mixed {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $variables = \array_merge($variables, [
            'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
        ]);

        $prepareStart = \microtime(true);

        // Prepare runtime
        if (!$this->runtimes->exists($runtimeName)) {
            if ($image === '' || $image === '0' || ($source === '' || $source === '0')) {
                throw new ExecutorException(ExecutorException::RUNTIME_NOT_FOUND, 'Runtime not found. Please start it first or provide runtime-related parameters.');
            }

            // Prepare request to executor
            $sendCreateRuntimeRequest = function () use ($runtimeId, $image, $source, $entrypoint, $variables, $cpus, $memory, $version, $restartPolicy, $runtimeEntrypoint): array {
                $ch = \curl_init();

                $body = \json_encode([
                    'runtimeId' => $runtimeId,
                    'image' => $image,
                    'source' => $source,
                    'entrypoint' => $entrypoint,
                    'variables' => $variables,
                    'cpus' => $cpus,
                    'memory' => $memory,
                    'version' => $version,
                    'restartPolicy' => $restartPolicy,
                    'runtimeEntrypoint' => $runtimeEntrypoint
                ]);

                \curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1/v1/runtimes");
                \curl_setopt($ch, CURLOPT_POST, true);
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: '');
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

                \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . \strlen($body ?: ''),
                    'authorization: Bearer ' . System::getEnv('OPR_EXECUTOR_SECRET', '')
                ]);

                $executorResponse = \curl_exec($ch);

                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

                $error = \curl_error($ch);

                $errNo = \curl_errno($ch);

                return [
                    'errNo' => $errNo,
                    'error' => $error,
                    'statusCode' => $statusCode,
                    'executorResponse' => $executorResponse
                ];
            };

            // Prepare runtime
            while (true) {
                // If timeout is passed, stop and return error
                if (\microtime(true) - $prepareStart >= $timeout) {
                    throw new ExecutorException(ExecutorException::RUNTIME_TIMEOUT);
                }

                ['errNo' => $errNo, 'error' => $error, 'statusCode' => $statusCode, 'executorResponse' => $executorResponse] = \call_user_func($sendCreateRuntimeRequest);

                if ($errNo === 0) {
                    $body = \is_string($executorResponse) ? \json_decode($executorResponse, true) : [];

                    if ($statusCode >= 500) {
                        // If the runtime has not yet attempted to start, it will return 500
                        $error = $body['message'];
                    } elseif ($statusCode >= 400 && $statusCode !== 409) {
                        // If the runtime fails to start, it will return 400, except for 409
                        // which indicates that the runtime is already being created
                        $error = $body['message'];
                        throw new \Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                    } else {
                        break;
                    }
                } elseif ($errNo !== 111) {
                    // Connection refused - see https://openswoole.com/docs/swoole-error-code
                    throw new \Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                }

                \usleep(500000); // 0.5s
            }
        }

        // Lower timeout by time it took to prepare container
        $timeout -= (\microtime(true) - $prepareStart);

        // Update runtimes
        $runtime = $this->runtimes->get($runtimeName);
        if ($runtime instanceof \OpenRuntimes\Executor\Runner\Runtime) {
            $runtime->updated = \time();
            $this->runtimes->set($runtimeName, $runtime);
        }

        // Ensure runtime started
        $launchStart = \microtime(true);
        while (true) {
            // If timeout is passed, stop and return error
            if (\microtime(true) - $launchStart >= $timeout) {
                throw new ExecutorException(ExecutorException::RUNTIME_TIMEOUT);
            }

            $runtimeStatus = $this->runtimes->get($runtimeName);
            if (!$runtimeStatus instanceof \OpenRuntimes\Executor\Runner\Runtime) {
                throw new ExecutorException(ExecutorException::RUNTIME_NOT_FOUND, 'Runtime no longer exists.');
            }

            if ($runtimeStatus->status !== 'pending') {
                break;
            }

            \usleep(500000); // 0.5s
        }

        // Lower timeout by time it took to launch container
        $timeout -= (\microtime(true) - $launchStart);

        // Ensure we have secret
        $runtime = $this->runtimes->get($runtimeName);
        if (!$runtime instanceof \OpenRuntimes\Executor\Runner\Runtime) {
            throw new ExecutorException(ExecutorException::RUNTIME_NOT_FOUND, 'Runtime secret not found. Please re-create the runtime.', 500);
        }

        $hostname = $runtime->hostname;
        $secret = $runtime->key;
        $runtimeImage = $runtime->image; // Executions on a running runtime may omit the image
        if ($secret === '' || $secret === '0') {
            throw new \Exception('Runtime secret not found. Please re-create the runtime.', 500);
        }

        $executeV2 = function () use ($variables, $payload, $secret, $hostname, $timeout): array {
            $statusCode = 0;
            $errNo = -1;
            $executorResponse = '';

            $ch = \curl_init();

            $body = \json_encode([
                'variables' => $variables,
                'payload' => $payload,
                'headers' => []
            ], JSON_FORCE_OBJECT);

            \curl_setopt($ch, CURLOPT_URL, "http://" . $hostname . ":3000/");
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: '');
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . \strlen($body ?: ''),
                'x-internal-challenge: ' . $secret,
                'host: null'
            ]);

            $executorResponse = \curl_exec($ch);

            $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $error = \curl_error($ch);

            $errNo = \curl_errno($ch);

            if ($errNo !== 0) {
                return [
                    'errNo' => $errNo,
                    'error' => $error,
                    'statusCode' => $statusCode,
                    'body' => '',
                    'logs' => '',
                    'errors' => '',
                    'headers' => []
                ];
            }

            // Extract response
            $executorResponse = json_decode(\strval($executorResponse), false);

            $res = $executorResponse->response ?? '';
            if (is_array($res)) {
                $res = json_encode($res, JSON_UNESCAPED_UNICODE);
            } elseif (is_object($res)) {
                $res = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
            }

            $stderr = $executorResponse->stderr ?? '';
            $stdout = $executorResponse->stdout ?? '';

            return [
                'errNo' => $errNo,
                'error' => $error,
                'statusCode' => $statusCode,
                'body' => $res,
                'logs' => $stdout,
                'errors' => $stderr,
                'headers' => []
            ];
        };

        $streamed = false; // Response is committed once streaming starts

        $executeV5 = function () use ($path, $method, $headers, $payload, $secret, $hostname, $runtimeImage, $timeout, $runtimeName, $logging, $headersCallback, $bodyCallback, &$streamed): array {
            $statusCode = 0;
            $errNo = -1;
            $executorResponse = '';

            $ch = \curl_init();

            $responseHeaders = [];

            if (!(\str_starts_with($path, '/'))) {
                $path = '/' . $path;
            }

            \curl_setopt($ch, CURLOPT_URL, "http://" . $hostname . ":3000" . $path);
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method ?: 'GET');
            \curl_setopt($ch, CURLOPT_NOBODY, \strtoupper($method) === 'HEAD');

            if (!in_array($payload, [null, '', '0'], true)) {
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            if ($headersCallback === null || $bodyCallback === null) {
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            }

            \curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) { // ignore invalid headers
                    return $len;
                }

                $key = strtolower(trim($header[0]));
                $value = trim($header[1]);

                if ($key === 'x-open-runtimes-log-id') {
                    $value = \urldecode($value);
                }

                if (\array_key_exists($key, $responseHeaders)) {
                    if (is_array($responseHeaders[$key])) {
                        $responseHeaders[$key][] = $value;
                    } else {
                        $responseHeaders[$key] = [$responseHeaders[$key], $value];
                    }
                } else {
                    $responseHeaders[$key] = $value;
                }

                return $len;
            });

            if ($headersCallback !== null && $bodyCallback !== null) {
                \curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use ($headersCallback, $bodyCallback, &$responseHeaders, &$streamed): int {
                    if (!$streamed) {
                        $streamed = true;

                        $outputHeaders = [];
                        foreach ($responseHeaders as $key => $value) {
                            if (\str_starts_with($key, 'x-open-runtimes-')) {
                                continue;
                            }

                            $outputHeaders[$key] = $value;
                        }

                        $headersCallback(\intval(\curl_getinfo($curl, CURLINFO_HTTP_CODE)), $outputHeaders);
                    }

                    $bodyCallback($data);

                    return \strlen($data);
                });
            }

            \curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout + 5); // Gives extra 5s after safe timeout to recieve response
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

            // Runtimes trust x-open-runtimes-* headers (the log id names a file before auth), so only the executor sets them
            $headers = \array_filter($headers, fn ($key): bool => !\str_starts_with(\strtolower((string) $key), 'x-open-runtimes-'), ARRAY_FILTER_USE_KEY);
            $headers['x-open-runtimes-logging'] = $logging ? 'enabled' : 'disabled';

            // Only the static runtime authenticates with Basic auth, and it reads the first Authorization value
            if (\str_starts_with($runtimeImage, 'openruntimes/static:')) {
                $headers = \array_filter($headers, fn ($key): bool => \strtolower((string) $key) !== 'authorization', ARRAY_FILTER_USE_KEY);
                $headers['Authorization'] = 'Basic ' . \base64_encode('opr:' . $secret);
            }

            $headers['x-open-runtimes-secret'] = $secret;

            $headers['x-open-runtimes-timeout'] = \max(\intval($timeout), 1);
            $headersArr = [];
            foreach ($headers as $key => $value) {
                $headersArr[] = $key . ': ' . $value;
            }

            \curl_setopt($ch, CURLOPT_HEADEROPT, CURLHEADER_UNIFIED);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $headersArr);

            $result = \curl_exec($ch);
            $executorResponse = \is_string($result) ? $result : '';

            $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $error = \curl_error($ch);

            $errNo = \curl_errno($ch);

            if ($errNo !== 0) {
                return [
                    'errNo' => $errNo,
                    'error' => $error,
                    'statusCode' => $statusCode,
                    'body' => '',
                    'logs' => '',
                    'errors' => '',
                    'headers' => $responseHeaders
                ];
            }

            // Extract logs and errors from file based on fileId in header
            $fileId = $responseHeaders['x-open-runtimes-log-id'] ?? '';
            if (\is_array($fileId)) {
                $fileId = $fileId[0];
            }

            $logs = '';
            $errors = '';
            // The runtime sends this id, so it may only name a file directly in its own logs directory.
            if ($fileId !== '' && $fileId !== '0' && \ctype_alnum($fileId)) {
                $logFile = '/tmp/' . $runtimeName . '/logs/' . $fileId . '_logs.log';
                $errorFile = '/tmp/' . $runtimeName . '/logs/' . $fileId . '_errors.log';

                // The runtime writes these files, so a symlink is refused, never followed. unlink
                // removes a symlink itself, never its target.
                $logs = RegularFile::read($logFile, 0, MAX_LOG_SIZE + 1);
                @\unlink($logFile);
                if (\strlen($logs) > MAX_LOG_SIZE) {
                    $logs = \substr($logs, 0, MAX_LOG_SIZE) . "\nLog file has been truncated to " . number_format(MAX_LOG_SIZE / 1048576, 2) . "MB.";
                }

                $errors = RegularFile::read($errorFile, 0, MAX_LOG_SIZE + 1);
                @\unlink($errorFile);
                if (\strlen($errors) > MAX_LOG_SIZE) {
                    $errors = \substr($errors, 0, MAX_LOG_SIZE) . "\nError file has been truncated to " . number_format(MAX_LOG_SIZE / 1048576, 2) . "MB.";
                }
            }

            $outputHeaders = [];
            foreach ($responseHeaders as $key => $value) {
                if (\str_starts_with($key, 'x-open-runtimes-')) {
                    continue;
                }

                $outputHeaders[$key] = $value;
            }

            return [
                'errNo' => $errNo,
                'error' => $error,
                'statusCode' => $statusCode,
                'body' => $executorResponse,
                'logs' => $logs,
                'errors' => $errors,
                'headers' => $outputHeaders
            ];
        };

        // From here we calculate billable duration of execution
        $startTime = \microtime(true);

        if ($runtime->listening === 0) {
            // Wait for cold-start to finish (app listening on port)
            $pingStart = \microtime(true);
            // A gone container's stale address drops the connect instead of refusing it, and the loop pings again anyway
            $validator = new TCP(1);
            // The first inspect waits until the runtime is 2s old, also for requests that joined its cold start
            $crashCheckAt = \max($pingStart, $runtime->created + 2);
            while (true) {
                // If timeout is passed, stop and return error
                if (\microtime(true) - $pingStart >= $timeout) {
                    throw new ExecutorException(ExecutorException::RUNTIME_TIMEOUT);
                }

                // Checked before the ping, which waits out its timeout once the container is gone
                if (\microtime(true) >= $crashCheckAt) {
                    $crash = $this->getCrash($runtimeName, $logging);
                    if ($crash !== null) {
                        throw new ExecutorException(ExecutorException::RUNTIME_FAILED, $crash);
                    }

                    $crashCheckAt = \microtime(true) + 3;
                }

                $online = $validator->isValid($hostname . ':' . 3000);
                if ($online) {
                    break;
                }

                \usleep(500000); // 0.5s
            }

            // Update swoole table
            $runtime = $this->runtimes->get($runtimeName);
            if ($runtime instanceof \OpenRuntimes\Executor\Runner\Runtime) {
                $runtime->listening = 1;
                $this->runtimes->set($runtimeName, $runtime);
            }

            // Lower timeout by time it took to cold-start
            $timeout -= (\microtime(true) - $pingStart);
        }

        // Execute function
        $executionRequest = $version === 'v2' ? $executeV2 : $executeV5;

        $retryDelayMs = \intval(System::getEnv('OPR_EXECUTOR_RETRY_DELAY_MS', '500'));
        $retryAttempts = \intval(System::getEnv('OPR_EXECUTOR_RETRY_ATTEMPTS', '5'));

        $attempts = 0;
        do {
            $executionResponse = \call_user_func($executionRequest);
            if ($executionResponse['errNo'] === CURLE_OK) {
                break;
            }

            // Not retryable, return error immediately
            if (!in_array($executionResponse['errNo'], [
                CURLE_COULDNT_RESOLVE_HOST, // 6
                CURLE_COULDNT_CONNECT, // 7
            ])) {
                break;
            }

            // Streamed responses cannot be retried
            if ($streamed) {
                break;
            }

            usleep($retryDelayMs * 1000);
        } while ((++$attempts < $retryAttempts) || (\microtime(true) - $startTime < $timeout));

        // Error occurred
        if ($executionResponse['errNo'] !== CURLE_OK) {
            // Intended timeout error for v2 functions
            if ($version === 'v2' && $executionResponse['errNo'] === SOCKET_ETIMEDOUT) {
                throw new ExecutorException(ExecutorException::EXECUTION_TIMEOUT, $executionResponse['error'], 400);
            }

            throw new \Exception('Internal curl error has occurred within the executor! Error Number: ' . $executionResponse['errNo'], 500);
        }

        // Successful execution
        ['statusCode' => $statusCode, 'body' => $body, 'logs' => $logs, 'errors' => $errors, 'headers' => $headers] = $executionResponse;

        $endTime = \microtime(true);
        $duration = $endTime - $startTime;

        if ($version === 'v2') {
            $logs = \mb_strcut($logs, 0, MAX_BUILD_LOG_SIZE);
            $errors = \mb_strcut($errors, 0, MAX_BUILD_LOG_SIZE);
        }

        $execution = [
            'statusCode' => $statusCode,
            'headers' => $headers,
            'body' => $body,
            'logs' => $logs,
            'errors' => $errors,
            'duration' => $duration,
            'startTime' => $startTime,
        ];

        // Update swoole table
        $runtime = $this->runtimes->get($runtimeName);
        if ($runtime instanceof \OpenRuntimes\Executor\Runner\Runtime) {
            $runtime->updated = \microtime(true);
            $this->runtimes->set($runtimeName, $runtime);
        }

        return $execution;
    }

    /**
     * Returns why a runtime's server exited, with the tail of its output when logging is enabled, or null while it may still start listening.
     */
    private function getCrash(string $runtimeName, bool $logging): ?string
    {
        $output = '';
        $stderr = '';
        $code = Console::execute('docker container inspect --format ' . \escapeshellarg('{{.State.Status}} {{.State.ExitCode}} {{.State.OOMKilled}}') . ' ' . \escapeshellarg($runtimeName), '', $output, $stderr, 5);
        if ($code !== 0) {
            return null;
        }

        [$status, $exitCode, $oomKilled] = \explode(' ', \trim($output)) + ['', '0', 'false'];

        // A running container may have restarted after a crash and serve now, so only the ping decides it
        if (!\in_array($status, ['restarting', 'exited', 'dead'], true)) {
            return null;
        }

        $details = ['exit code ' . $exitCode];
        if ($oomKilled === 'true') {
            $details[] = 'out of memory';
        }

        $message = 'Runtime crashed while starting (' . \implode(', ', $details) . ')';

        if (!$logging) {
            return $message . '.';
        }

        // tail -c bounds what is buffered, since --tail limits lines and not their length
        $failed = 'OPR_LOGS_FAILED';
        $logs = '';
        $code = Console::execute('(docker logs --tail 100 ' . \escapeshellarg($runtimeName) . ' 2>&1 || echo ' . $failed . ') | tail -c ' . (10 * 1024), '', $logs, $stderr, 5);
        $logs = \trim($logs);
        if ($code !== 0 || $logs === '' || \str_ends_with($logs, $failed)) {
            return $message . '.';
        }

        // Invalid UTF-8, including a character cut by tail, would fail the JSON encoding of the error response
        $logs = \mb_convert_encoding($logs, 'UTF-8', 'UTF-8');

        return $message . ":\n" . $logs;
    }

    public function getRuntimes(): mixed
    {
        $runtimes = [];
        foreach ($this->runtimes as $runtime) {
            $runtimes[] = $runtime->toArray();
        }

        return $runtimes;
    }

    public function getRuntime(string $name): mixed
    {
        $runtime = $this->runtimes->get($name);
        if (!$runtime instanceof \OpenRuntimes\Executor\Runner\Runtime) {
            throw new ExecutorException(ExecutorException::RUNTIME_NOT_FOUND);
        }

        return $runtime->toArray();
    }
}
