<?php

namespace OpenRuntimes\Executor\Runner;

use OpenRuntimes\Executor\Logs;
use Appwrite\Runtimes\Runtimes;
use OpenRuntimes\Executor\Exception;
use OpenRuntimes\Executor\Stats;
use OpenRuntimes\Executor\Usage;
use OpenRuntimes\Executor\Validator\TCP;
use Swoole\Process;
use Swoole\Table;
use Swoole\Timer;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\Logger\Log;
use Utopia\Orchestration\Orchestration;
use Utopia\Orchestration\Exception\Timeout as TimeoutException;
use Utopia\Orchestration\Exception\Orchestration as OrchestrationException;
use Utopia\Storage\Device\Local;
use Utopia\System\System;

use function Swoole\Coroutine\batch;

class Docker extends Adapter
{
    private Table $activeRuntimes;
    private Stats $stats;
    /**
     * @var string[]
     */
    private array $networks;

    /**
     * @param Orchestration $orchestration
     * @param string[] $networks
     */
    public function __construct(private readonly Orchestration $orchestration, array $networks)
    {
        $this->activeRuntimes = new Table(4096);

        $this->activeRuntimes->column('version', Table::TYPE_STRING, 32);
        $this->activeRuntimes->column('created', Table::TYPE_FLOAT);
        $this->activeRuntimes->column('updated', Table::TYPE_FLOAT);
        $this->activeRuntimes->column('name', Table::TYPE_STRING, 1024);
        $this->activeRuntimes->column('hostname', Table::TYPE_STRING, 1024);
        $this->activeRuntimes->column('status', Table::TYPE_STRING, 256);
        $this->activeRuntimes->column('key', Table::TYPE_STRING, 1024);
        $this->activeRuntimes->column('listening', Table::TYPE_INT, 1);
        $this->activeRuntimes->column('image', Table::TYPE_STRING, 1024);
        $this->activeRuntimes->column('initialised', Table::TYPE_INT, 0);
        $this->activeRuntimes->create();

        $this->stats = new Stats();

        $this->init($networks);
    }

    /**
     * @param string[] $networks
     * @return void
     * @throws \Utopia\Http\Exception
     */
    private function init(array $networks): void
    {
        /*
         * Remove residual runtimes and networks
         */
        Console::info('Removing orphan runtimes and networks...');
        $this->cleanUp();
        Console::success("Orphan runtimes and networks removal finished.");

        /**
         * Create and store Docker Bridge networks used for communication between executor and runtimes
         */
        Console::info('Creating networks...');
        $createdNetworks = $this->createNetworks($networks);
        $this->networks = $createdNetworks;

        /**
         * Warmup: make sure images are ready to run fast 🚀
         */
        $allowList = empty(Http::getEnv('OPR_EXECUTOR_RUNTIMES')) ? [] : \explode(',', Http::getEnv('OPR_EXECUTOR_RUNTIMES'));

        if (Http::getEnv('OPR_EXECUTOR_IMAGE_PULL', 'enabled') === 'disabled') {
            // Useful to prevent auto-pulling from remote when testing local images
            Console::info("Skipping image pulling");
        } else {
            $runtimeVersions = \explode(',', Http::getEnv('OPR_EXECUTOR_RUNTIME_VERSIONS', 'v5') ?? 'v5');
            foreach ($runtimeVersions as $runtimeVersion) {
                Console::success("Pulling $runtimeVersion images...");
                $runtimes = new Runtimes($runtimeVersion); // TODO: @Meldiron Make part of open runtimes
                $runtimes = $runtimes->getAll(true, $allowList);
                $callables = [];
                foreach ($runtimes as $runtime) {
                    $callables[] = function () use ($runtime) {
                        Console::log('Warming up ' . $runtime['name'] . ' ' . $runtime['version'] . ' environment...');
                        $response = $this->orchestration->pull($runtime['image']);
                        if ($response) {
                            Console::info("Successfully Warmed up {$runtime['name']} {$runtime['version']}!");
                        } else {
                            Console::warning("Failed to Warmup {$runtime['name']} {$runtime['version']}!");
                        }
                    };
                }

                batch($callables);
            }
        }

        Console::success("Image pulling finished.");

        /**
         * Run a maintenance worker every X seconds to remove inactive runtimes
         */
        Console::info('Starting maintenance interval...');
        $interval = (int)Http::getEnv('OPR_EXECUTOR_MAINTENANCE_INTERVAL', '3600'); // In seconds
        Timer::tick($interval * 1000, function () {
            Console::info("Running maintenance task ...");
            // Stop idling runtimes
            foreach ($this->activeRuntimes as $runtimeName => $runtime) {
                $inactiveThreshold = \time() - \intval(Http::getEnv('OPR_EXECUTOR_INACTIVE_THRESHOLD', '60'));
                if ($runtime['updated'] < $inactiveThreshold) {
                    go(function () use ($runtimeName, $runtime) {
                        try {
                            $this->orchestration->remove($runtime['name'], true);
                            Console::success("Successfully removed {$runtime['name']}");
                        } catch (\Throwable $th) {
                            Console::error('Inactive Runtime deletion failed: ' . $th->getMessage());
                        } finally {
                            $this->activeRuntimes->del($runtimeName);
                        }
                    });
                }
            }

            // Clear leftover build folders
            $localDevice = new Local();
            $tmpPath = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
            $entries = $localDevice->getFiles($tmpPath);
            $prefix = $tmpPath . System::getHostname() . '-';
            foreach ($entries as $entry) {
                if (\str_starts_with($entry, $prefix)) {
                    $isActive = false;

                    foreach ($this->activeRuntimes as $runtimeName => $runtime) {
                        if (\str_ends_with($entry, $runtimeName)) {
                            $isActive = true;
                            break;
                        }
                    }

                    if (!$isActive) {
                        $localDevice->deletePath($entry);
                    }
                }
            }

            Console::success("Maintanance task finished.");
        });

        Console::success('Maintenance interval started.');

        /**
         * Get usage stats every X seconds to update swoole table
         */
        Console::info('Starting stats interval...');
        $getStats = function (): void {
            // Get usage stats
            $usage = new Usage($this->orchestration);
            $usage->run();
            $this->stats->updateStats($usage);
        };

        // Load initial stats in blocking way
        $getStats();

        // Setup infinite recursion in non-blocking way
        \go(fn () => Timer::after(1000, fn () => $getStats()));

        Console::success('Stats interval started.');

        Process::signal(SIGINT, fn () => $this->cleanUp($this->networks));
        Process::signal(SIGQUIT, fn () => $this->cleanUp($this->networks));
        Process::signal(SIGKILL, fn () => $this->cleanUp($this->networks));
        Process::signal(SIGTERM, fn () => $this->cleanUp($this->networks));
    }

    /**
     * @param string $runtimeId
     * @param int $timeout
     * @param Response $response
     * @param Log $log
     * @return void
     */
    public function getLogs(string $runtimeId, int $timeout, Response $response, Log $log): void
    {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $tmpFolder = "tmp/$runtimeName/";
        $tmpLogging = "/{$tmpFolder}logging"; // Build logs

        // TODO: Combine 3 checks below into one

        // Wait for runtime
        for ($i = 0; $i < 10; $i++) {
            $output = '';
            $code = Console::execute('docker container inspect ' . \escapeshellarg($runtimeName), '', $output);
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
                throw new Exception(Exception::RUNTIME_TIMEOUT);
            }

            $runtime = $this->activeRuntimes->get($runtimeName);
            if (!empty($runtime)) {
                $version = $runtime['version'];
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
                throw new Exception(Exception::LOGS_TIMEOUT);
            }

            if (\file_exists($tmpLogging . '/logs.txt') && \file_exists($tmpLogging . '/timings.txt')) {
                $timings = \file_get_contents($tmpLogging . '/timings.txt') ?: '';
                if (\strlen($timings) > 0) {
                    break;
                }
            }

            // Ensure runtime is still present
            $runtime = $this->activeRuntimes->get($runtimeName);
            if (empty($runtime)) {
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
        $activeRuntimes = $this->activeRuntimes;
        $timerId = Timer::tick($streamInterval, function () use (&$logsProcess, &$logsChunk, $response, $activeRuntimes, $runtimeName) {
            $runtime = $activeRuntimes->get($runtimeName);
            if ($runtime['initialised'] === 1) {
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

            if (!$write) {
                if (!empty($logsProcess)) {
                    \proc_terminate($logsProcess, 9);
                }
            }
        });

        $offset = 0; // Current offset from timing for reading logs content
        $tempLogsContent = \file_get_contents($tmpLogging . '/logs.txt') ?: '';
        $introOffset = Logs::getLogOffset($tempLogsContent);

        $datetime = new \DateTime("now", new \DateTimeZone("UTC")); // Date used for tracking absolute log timing

        $output = ''; // Unused, just a refference for stdout
        Console::execute('tail -F ' . $tmpLogging . '/timings.txt', '', $output, $timeout, function (string $timingChunk, mixed $process) use ($tmpLogging, &$logsChunk, &$logsProcess, &$datetime, &$offset, $introOffset) {
            $logsProcess = $process;

            if (!\file_exists($tmpLogging . '/logs.txt')) {
                if (!empty($logsProcess)) {
                    \proc_terminate($logsProcess, 9);
                }
                return;
            }

            $parts = Logs::parseTiming($timingChunk, $datetime);

            foreach ($parts as $part) {
                $timestamp = $part['timestamp'] ?? '';
                $length = \intval($part['length'] ?? '0');

                $logContent = \file_get_contents($tmpLogging . '/logs.txt', false, null, $introOffset + $offset, \abs($length)) ?: '';

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

        if (!$this->activeRuntimes->exists($runtimeName)) {
            throw new Exception(Exception::RUNTIME_NOT_FOUND);
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
            throw new Exception(Exception::COMMAND_TIMEOUT, previous: $e);
        } catch (OrchestrationException $e) {
            throw new Exception(Exception::COMMAND_FAILED, previous: $e);
        }
    }

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
     * @param Log $log
     * @return mixed
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
        Log $log,
        string $region = '',
    ): mixed {
        $runtimeName = System::getHostname() . '-' . $runtimeId;
        $runtimeHostname = \bin2hex(\random_bytes(16));

        if ($this->activeRuntimes->exists($runtimeName)) {
            if ($this->activeRuntimes->get($runtimeName)['status'] == 'pending') {
                throw new Exception(Exception::RUNTIME_CONFLICT, 'A runtime with the same ID is already being created. Attempt a execution soon.');
            }

            throw new Exception(Exception::RUNTIME_CONFLICT);
        }

        $container = [];
        $output = [];
        $startTime = \microtime(true);

        $this->activeRuntimes->set($runtimeName, [
            'version' => $version,
            'listening' => 0,
            'name' => $runtimeName,
            'hostname' => $runtimeHostname,
            'created' => $startTime,
            'updated' => $startTime,
            'status' => 'pending',
            'key' => $secret,
            'image' => $image,
            'initialised' => 0,
        ]);

        /**
         * Temporary file paths in the executor
         */
        $buildFile = "code.tar.gz";
        if (($variables['OPEN_RUNTIMES_BUILD_COMPRESSION'] ?? '') === 'none') {
            $buildFile = "code.tar";
        }

        $sourceFile = "code.tar.gz";
        if (!empty($source) && \pathinfo($source, PATHINFO_EXTENSION) === 'tar') {
            $sourceFile = "code.tar";
        }

        $tmpFolder = "tmp/$runtimeName/";
        $tmpSource = "/{$tmpFolder}src/$sourceFile";
        $tmpBuild = "/{$tmpFolder}builds/$buildFile";
        $tmpLogging = "/{$tmpFolder}logging"; // Build logs
        $tmpLogs = "/{$tmpFolder}logs"; // Runtime logs

        $sourceDevice = $this->getStorageDevice("/");
        $localDevice = new Local();

        try {
            /**
             * Copy code files from source to a temporary location on the executor
             */
            if (!empty($source)) {
                if (!$sourceDevice->transfer($source, $tmpSource, $localDevice)) {
                    throw new \Exception('Failed to copy source code to temporary directory');
                };
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

            if (empty($runtimeEntrypoint)) {
                if ($version === 'v2' && empty($command)) {
                    $runtimeEntrypointCommands = [];
                } else {
                    $runtimeEntrypointCommands = ['tail', '-f', '/dev/null'];
                }
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

            if (empty($containerId)) {
                throw new \Exception('Failed to create runtime');
            }

            /**
             * Execute any commands if they were provided
             */
            if (!empty($command)) {
                if ($version === 'v2') {
                    $commands = [
                        'sh',
                        '-c',
                        'touch /var/tmp/logs.txt && (' . $command . ') >> /var/tmp/logs.txt 2>&1 && cat /var/tmp/logs.txt'
                    ];
                } else {
                    $commands = [
                        'bash',
                        '-c',
                        'mkdir -p /tmp/logging && touch /tmp/logging/timings.txt && touch /tmp/logging/logs.txt && script --log-out /tmp/logging/logs.txt --flush --log-timing /tmp/logging/timings.txt --return --quiet --command "' . \str_replace('"', '\"', $command) . '"'
                    ];
                }

                try {
                    $stdout = '';
                    $status = $this->orchestration->execute(
                        name: $runtimeName,
                        command: $commands,
                        output: $stdout,
                        timeout: $timeout
                    );

                    if (!$status) {
                        throw new Exception(Exception::RUNTIME_FAILED, "Failed to create runtime: $stdout");
                    }

                    if ($version === 'v2') {
                        $stdout = \mb_substr($stdout ?: 'Runtime created successfully!', -MAX_BUILD_LOG_SIZE); // Limit to 1MB
                        $output[] = [
                            'timestamp' => Logs::getTimestamp(),
                            'content' => $stdout
                        ];
                    } else {
                        $output = Logs::get($runtimeName);
                    }
                } catch (Throwable $err) {
                    throw new Exception(Exception::RUNTIME_FAILED, $err->getMessage(), null, $err);
                }
            }

            /**
             * Move built code to expected build directory
             */
            if (!empty($destination)) {
                // Check if the build was successful by checking if file exists
                if (!$localDevice->exists($tmpBuild)) {
                    throw new \Exception('Something went wrong when starting runtime.');
                }

                $size = $localDevice->getFileSize($tmpBuild);
                $container['size'] = $size;

                $destinationDevice = $this->getStorageDevice($destination);
                $path = $destinationDevice->getPath(\uniqid() . '.' . \pathinfo($tmpBuild, PATHINFO_EXTENSION));

                if (!$localDevice->transfer($tmpBuild, $path, $destinationDevice)) {
                    throw new \Exception('Failed to move built code to storage');
                };

                $container['path'] = $path;
            }

            $endTime = \microtime(true);
            $duration = $endTime - $startTime;

            $container = array_merge($container, [
                'output' => $output,
                'startTime' => $startTime,
                'duration' => $duration,
            ]);

            $activeRuntime = $this->activeRuntimes->get($runtimeName);
            $activeRuntime['updated'] = \microtime(true);
            $activeRuntime['status'] = 'Up ' . \round($duration, 2) . 's';
            $activeRuntime['initialised'] = 1;
            $this->activeRuntimes->set($runtimeName, $activeRuntime);
        } catch (Throwable $th) {
            if ($version === 'v2') {
                $message = !empty($output) ? $output : $th->getMessage();
                try {
                    $logs = '';
                    $status = $this->orchestration->execute(
                        name: $runtimeName,
                        command: ['sh', '-c', 'cat /var/tmp/logs.txt'],
                        output: $logs,
                        timeout: 15
                    );

                    if (!empty($logs)) {
                        $message = $logs;
                    }

                    $message = \mb_substr($message, -MAX_BUILD_LOG_SIZE); // Limit to 1MB
                } catch (Throwable $err) {
                    // Ignore, use fallback error message
                }

                $output = [
                    'timestamp' => Logs::getTimestamp(),
                    'content' => $message
                ];
            } else {
                $output = Logs::get($runtimeName);
                $output = \count($output) > 0 ? $output : [[
                    'timestamp' => Logs::getTimestamp(),
                    'content' => $th->getMessage()
                ]];
            }

            if ($remove) {
                \sleep(2); // Allow time to read logs
            }

            // Silently try to kill container
            try {
                $this->orchestration->remove($runtimeName, true);
            } catch (Throwable $th) {
            }

            $localDevice->deletePath($tmpFolder);
            $this->activeRuntimes->del($runtimeName);

            $message = '';
            foreach ($output as $chunk) {
                $message .= $chunk['content'];
            }

            throw new \Exception($message, $th->getCode() ?: 500, $th);
        }

        // Container cleanup
        if ($remove) {
            \sleep(2); // Allow time to read logs

            // Silently try to kill container
            try {
                $this->orchestration->remove($runtimeName, true);
            } catch (Throwable $th) {
            }

            $localDevice->deletePath($tmpFolder);
            $this->activeRuntimes->del($runtimeName);
        }

        // Remove weird symbol characters (for example from Next.js)
        if (\is_array($container['output'])) {
            foreach ($container['output'] as $index => &$chunk) {
                $chunk['content'] = \mb_convert_encoding($chunk['content'] ?? '', 'UTF-8', 'UTF-8');
            }
        }

        return $container;
    }

    /**
     * @param string $runtimeId
     * @param Log $log
     * @return void
     */
    public function deleteRuntime(string $runtimeId, Log $log): void
    {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        if (!$this->activeRuntimes->exists($runtimeName)) {
            throw new Exception(Exception::RUNTIME_NOT_FOUND);
        }

        $this->orchestration->remove($runtimeName, true);
        $this->activeRuntimes->del($runtimeName);
    }

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
     * @param Log $log
     * @return mixed
     * @throws Exception
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
        Log $log,
        string $region = '',
    ): mixed {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $variables = \array_merge($variables, [
            'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
        ]);

        $prepareStart = \microtime(true);

        // Prepare runtime
        if (!$this->activeRuntimes->exists($runtimeName)) {
            if (empty($image) || empty($source)) {
                throw new Exception(Exception::RUNTIME_NOT_FOUND, 'Runtime not found. Please start it first or provide runtime-related parameters.');
            }

            // Prepare request to executor
            $sendCreateRuntimeRequest = function () use ($runtimeId, $image, $source, $entrypoint, $variables, $cpus, $memory, $version, $restartPolicy, $runtimeEntrypoint) {
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
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

                \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . \strlen($body ?: ''),
                    'authorization: Bearer ' . Http::getEnv('OPR_EXECUTOR_SECRET', '')
                ]);

                $executorResponse = \curl_exec($ch);

                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

                $error = \curl_error($ch);

                $errNo = \curl_errno($ch);

                \curl_close($ch);

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
                    throw new Exception(Exception::RUNTIME_TIMEOUT);
                }

                ['errNo' => $errNo, 'error' => $error, 'statusCode' => $statusCode, 'executorResponse' => $executorResponse] = \call_user_func($sendCreateRuntimeRequest);

                if ($errNo === 0) {
                    if (\is_string($executorResponse)) {
                        $body = \json_decode($executorResponse, true);
                    } else {
                        $body = [];
                    }

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

        // Update swoole table
        $runtime = $this->activeRuntimes->get($runtimeName) ?? [];

        $runtime['updated'] = \time();
        $this->activeRuntimes->set($runtimeName, $runtime);

        // Ensure runtime started
        $launchStart = \microtime(true);
        while (true) {
            // If timeout is passed, stop and return error
            if (\microtime(true) - $launchStart >= $timeout) {
                throw new Exception(Exception::RUNTIME_TIMEOUT);
            }

            if ($this->activeRuntimes->get($runtimeName)['status'] !== 'pending') {
                break;
            }

            \usleep(500000); // 0.5s
        }

        // Lower timeout by time it took to launch container
        $timeout -= (\microtime(true) - $launchStart);

        // Ensure we have secret
        $runtime = $this->activeRuntimes->get($runtimeName);
        $hostname = $runtime['hostname'];
        $secret = $runtime['key'];
        if (empty($secret)) {
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
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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

            \curl_close($ch);

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

        $executeV5 = function () use ($path, $method, $headers, $payload, $secret, $hostname, $timeout, $runtimeName, $logging): array {
            $statusCode = 0;
            $errNo = -1;
            $executorResponse = '';

            $ch = \curl_init();

            $responseHeaders = [];

            if (!(\str_starts_with($path, '/'))) {
                $path = '/' . $path;
            }

            \curl_setopt($ch, CURLOPT_URL, "http://" . $hostname . ":3000" . $path);
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            \curl_setopt($ch, CURLOPT_NOBODY, \strtoupper($method) === 'HEAD');
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) { // ignore invalid headers
                    return $len;
                }

                $key = strtolower(trim($header[0]));
                $value = trim($header[1]);

                if (\in_array($key, ['x-open-runtimes-log-id'])) {
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

            \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout + 5); // Gives extra 5s after safe timeout to recieve response
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            if ($logging === true) {
                $headers['x-open-runtimes-logging'] = 'enabled';
            } else {
                $headers['x-open-runtimes-logging'] = 'disabled';
            }

            $headers['Authorization'] = 'Basic ' . \base64_encode('opr:' . $secret);
            $headers['x-open-runtimes-secret'] = $secret;

            $headers['x-open-runtimes-timeout'] = \max(\intval($timeout), 1);
            $headersArr = [];
            foreach ($headers as $key => $value) {
                $headersArr[] = $key . ': ' . $value;
            }

            \curl_setopt($ch, CURLOPT_HEADEROPT, CURLHEADER_UNIFIED);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $headersArr);

            $executorResponse = \curl_exec($ch);

            $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $error = \curl_error($ch);

            $errNo = \curl_errno($ch);

            \curl_close($ch);

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
                $fileId = $fileId[0] ?? '';
            }
            $logs = '';
            $errors = '';
            if (!empty($fileId)) {
                $logFile = '/tmp/' . $runtimeName . '/logs/' . $fileId . '_logs.log';
                $errorFile = '/tmp/' . $runtimeName . '/logs/' . $fileId . '_errors.log';

                $logDevice = new Local();

                if ($logDevice->exists($logFile)) {
                    if ($logDevice->getFileSize($logFile) > MAX_LOG_SIZE) {
                        $maxToRead = MAX_LOG_SIZE;
                        $logs = $logDevice->read($logFile, 0, $maxToRead);
                        $logs .= "\nLog file has been truncated to " . number_format(MAX_LOG_SIZE / 1048576, 2) . "MB.";
                    } else {
                        $logs = $logDevice->read($logFile);
                    }

                    $logDevice->delete($logFile);
                }

                if ($logDevice->exists($errorFile)) {
                    if ($logDevice->getFileSize($errorFile) > MAX_LOG_SIZE) {
                        $maxToRead = MAX_LOG_SIZE;
                        $errors = $logDevice->read($errorFile, 0, $maxToRead);
                        $errors .= "\nError file has been truncated to " . number_format(MAX_LOG_SIZE / 1048576, 2) . "MB.";
                    } else {
                        $errors = $logDevice->read($errorFile);
                    }

                    $logDevice->delete($errorFile);
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

        $listening = $runtime['listening'];

        if (empty($listening)) {
            // Wait for cold-start to finish (app listening on port)
            $pingStart = \microtime(true);
            $validator = new TCP();
            while (true) {
                // If timeout is passed, stop and return error
                if (\microtime(true) - $pingStart >= $timeout) {
                    throw new Exception(Exception::RUNTIME_TIMEOUT);
                }

                $online = $validator->isValid($hostname . ':' . 3000);
                if ($online) {
                    break;
                }

                \usleep(500000); // 0.5s
            }

            // Update swoole table
            $runtime = $this->activeRuntimes->get($runtimeName);
            $runtime['listening'] = 1;
            $this->activeRuntimes->set($runtimeName, $runtime);

            // Lower timeout by time it took to cold-start
            $timeout -= (\microtime(true) - $pingStart);
        }

        // Execute function
        $executionRequest = $version === 'v2' ? $executeV2 : $executeV5;

        $retryDelayMs = \intval(Http::getEnv('OPR_EXECUTOR_RETRY_DELAY_MS', '500'));
        $retryAttempts = \intval(Http::getEnv('OPR_EXECUTOR_RETRY_ATTEMPTS', '5'));

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

            usleep($retryDelayMs * 1000);
        } while ((++$attempts < $retryAttempts) || (\microtime(true) - $startTime < $timeout));

        // Error occurred
        if ($executionResponse['errNo'] !== CURLE_OK) {
            // Intended timeout error for v2 functions
            if ($version === 'v2' && $executionResponse['errNo'] === SOCKET_ETIMEDOUT) {
                throw new Exception(Exception::EXECUTION_TIMEOUT, $executionResponse['error'], 400);
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
        $runtime = $this->activeRuntimes->get($runtimeName);
        $runtime['updated'] = \microtime(true);
        $this->activeRuntimes->set($runtimeName, $runtime);

        return $execution;
    }

    /**
     * @param string[] $networks
     * @return void
     */
    private function cleanUp(array $networks = []): void
    {
        Console::log('Cleaning up containers and networks...');

        $functionsToRemove = $this->orchestration->list(['label' => 'openruntimes-executor=' . System::getHostname()]);

        if (\count($functionsToRemove) === 0) {
            Console::info('No containers found to clean up.');
        }

        $jobsRuntimes = [];
        foreach ($functionsToRemove as $container) {
            $jobsRuntimes[] = function () use ($container) {
                try {
                    $this->orchestration->remove($container->getId(), true);

                    $activeRuntimeId = $container->getName();

                    if (!$this->activeRuntimes->exists($activeRuntimeId)) {
                        $this->activeRuntimes->del($activeRuntimeId);
                    }

                    Console::success('Removed container ' . $container->getName());
                } catch (\Throwable $th) {
                    Console::error('Failed to remove container: ' . $container->getName());
                    Console::error($th);
                }
            };
        }
        batch($jobsRuntimes);

        $jobsNetworks = [];
        foreach ($networks as $network) {
            $jobsNetworks[] = function () use ($network) {
                try {
                    $this->orchestration->removeNetwork($network);
                    Console::success("Removed network: $network");
                } catch (Exception $e) {
                    Console::error("Failed to remove network $network: " . $e->getMessage());
                }
            };
        }
        batch($jobsNetworks);

        Console::success('Cleanup finished.');
    }

    /**
     * @param string[] $networks
     * @return string[]
     */
    private function createNetworks(array $networks): array
    {
        $jobs = [];
        $createdNetworks = [];
        foreach ($networks as $network) {
            $jobs[] = function () use ($network, &$createdNetworks) {
                if (!$this->orchestration->networkExists($network)) {
                    try {
                        $this->orchestration->createNetwork($network, false);
                        Console::success("Created network: $network");
                        $createdNetworks[] = $network;
                    } catch (\Throwable $e) {
                        Console::error("Failed to create network $network: " . $e->getMessage());
                    }
                } else {
                    Console::info("Network $network already exists");
                    $createdNetworks[] = $network;
                }
            };
        }
        batch($jobs);

        $image = Http::getEnv('OPR_EXECUTOR_IMAGE', '');
        $containers = $this->orchestration->list(['label' => "com.openruntimes.executor.image=$image"]);

        if (count($containers) < 1) {
            $containerName = '';
            Console::warning('No matching executor found. Please check the value of OPR_EXECUTOR_IMAGE. Executor will need to be connected to the runtime network manually.');
        } else {
            $containerName = $containers[0]->getName();
            Console::success('Found matching executor. Executor will be connected to runtime network automatically.');
        }

        if (!empty($containerName)) {
            foreach ($createdNetworks as $network) {
                try {
                    $this->orchestration->networkConnect($containerName, $network);
                    Console::success("Successfully connected executor '$containerName' to network '$network'");
                } catch (\Throwable $e) {
                    Console::error("Failed to connect executor '$containerName' to network '$network': " . $e->getMessage());
                }
            }
        }

        return $createdNetworks;
    }

    public function getRuntimes(): mixed
    {
        $runtimes = [];
        foreach ($this->activeRuntimes as $runtime) {
            $runtimes[] = $runtime;
        }
        return $runtimes;
    }

    public function getRuntime(string $name): mixed
    {
        if (!$this->activeRuntimes->exists($name)) {
            throw new Exception(Exception::RUNTIME_NOT_FOUND);
        }

        return $this->activeRuntimes->get($name);
    }

    public function getStats(): Stats
    {
        return $this->stats;
    }
}
