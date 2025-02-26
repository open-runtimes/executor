<?php

namespace OpenRuntimes\Executor\Runner;

use Appwrite\Runtimes\Runtimes;
use Exception;
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

        $this->activeRuntimes->column('created', Table::TYPE_FLOAT);
        $this->activeRuntimes->column('updated', Table::TYPE_FLOAT);
        $this->activeRuntimes->column('name', Table::TYPE_STRING, 1024);
        $this->activeRuntimes->column('hostname', Table::TYPE_STRING, 1024);
        $this->activeRuntimes->column('status', Table::TYPE_STRING, 256);
        $this->activeRuntimes->column('key', Table::TYPE_STRING, 1024);
        $this->activeRuntimes->column('listening', Table::TYPE_INT, 1);
        $this->activeRuntimes->column('image', Table::TYPE_STRING, 1024);
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
            $runtimeVersions = \explode(',', Http::getEnv('OPR_EXECUTOR_RUNTIME_VERSIONS', 'v4') ?? 'v4');
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
                $inactiveThreshold = \time() - \intval(Http::getEnv('OPR_EXECUTOR_INACTIVE_TRESHOLD', '60'));
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

        // Wait for runtime
        for ($i = 0; $i < 10; $i++) {
            $output = '';
            $code = Console::execute('docker container inspect ' . \escapeshellarg($runtimeName), '', $output);
            if ($code === 0) {
                break;
            }

            if ($i === 9) {
                $runtimeIdTokens = explode("-", $runtimeName);
                $executorId = $runtimeIdTokens[0];
                $functionId = $runtimeIdTokens[1];
                $deploymentId = $runtimeIdTokens[2];
                $log->addTag('executorId', $executorId);
                $log->addTag('functionId', $functionId);
                $log->addTag('deploymentId', $deploymentId);
                throw new Exception('Runtime not ready. Container not found.', 500);
            }

            \usleep(500000);
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
        $timerId = Timer::tick($streamInterval, function () use (&$logsProcess, &$logsChunk, $response) {
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

        $output = '';
        Console::execute('docker exec ' . \escapeshellarg($runtimeName) . ' tail -F /var/tmp/logs.txt', '', $output, $timeout, function (string $outputChunk, mixed $process) use (&$logsChunk, &$logsProcess) {
            $logsProcess = $process;

            if (!empty($outputChunk)) {
                $logsChunk .= $outputChunk;
            }
        });

        Timer::clear($timerId);
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
    public function createRuntime(string $runtimeId, string $secret, string $image, string $entrypoint, string $source, string $destination, array $variables, string $runtimeEntrypoint, string $command, int $timeout, bool $remove, float $cpus, int $memory, string $version, string $restartPolicy, Log $log): mixed
    {
        $runtimeName = System::getHostname() . '-' . $runtimeId;
        $runtimeHostname = \bin2hex(\random_bytes(16));

        $log->addTag('image', $image);
        $log->addTag('version', $version);
        $log->addTag('runtimeId', $runtimeName);

        if ($this->activeRuntimes->exists($runtimeName)) {
            if ($this->activeRuntimes->get($runtimeName)['status'] == 'pending') {
                throw new \Exception('A runtime with the same ID is already being created. Attempt a execution soon.', 409);
            }

            throw new Exception('Runtime already exists.', 409);
        }

        $container = [];
        $output = '';
        $startTime = \microtime(true);

        $this->activeRuntimes->set($runtimeName, [
            'listening' => 0,
            'name' => $runtimeName,
            'hostname' => $runtimeHostname,
            'created' => $startTime,
            'updated' => $startTime,
            'status' => 'pending',
            'key' => $secret,
            'image' => $image,
        ]);

        /**
         * Temporary file paths in the executor
         */
        $tmpFolder = "tmp/$runtimeName/";
        $tmpSource = "/{$tmpFolder}src/code.tar.gz";
        $tmpBuild = "/{$tmpFolder}builds/code.tar.gz";
        $tmpLogs = "/{$tmpFolder}logs";

        $sourceDevice = $this->getStorageDevice("/");
        $localDevice = new Local();

        try {
            /**
             * Copy code files from source to a temporary location on the executor
             */
            if (!empty($source)) {
                if (!$sourceDevice->transfer($source, $tmpSource, $localDevice)) {
                    throw new Exception('Failed to copy source code to temporary directory', 500);
                };
            }

            /**
             * Create the mount folder
             */
            if (!$localDevice->createDirectory(\dirname($tmpBuild))) {
                throw new Exception("Failed to create temporary directory", 500);
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
                $runtimeEntrypointCommands = ['sh', '-c', $runtimeEntrypoint];
            }

            $codeMountPath = $version === 'v2' ? '/usr/code' : '/mnt/code';
            $workdir = $version === 'v2' ? '/usr/code' : '';

            $network = $this->networks[array_rand($this->networks)];

            $volumes = [
                \dirname($tmpSource) . ':/tmp:rw',
                \dirname($tmpBuild) . ':' . $codeMountPath . ':rw',
            ];

            if ($version === 'v4') {
                $volumes[] = \dirname($tmpLogs . '/logs') . ':/mnt/logs:rw';
            }

            /** Keep the container alive if we have commands to be executed */
            $containerId = $this->orchestration->run(
                image: $image,
                name: $runtimeName,
                hostname: $runtimeHostname,
                vars: $variables,
                command: $runtimeEntrypointCommands,
                labels: [
                    'openruntimes-executor' => System::getHostname(),
                    'openruntimes-runtime-id' => $runtimeId
                ],
                volumes: $volumes,
                network: \strval($network),
                workdir: $workdir,
                restart: $restartPolicy
            );

            if (empty($containerId)) {
                throw new Exception('Failed to create runtime', 500);
            }

            /**
             * Execute any commands if they were provided
             */
            if (!empty($command)) {
                $commands = [
                    'sh',
                    '-c',
                    'touch /var/tmp/logs.txt && (' . $command . ') >> /var/tmp/logs.txt 2>&1 && cat /var/tmp/logs.txt'
                ];

                try {
                    $status = $this->orchestration->execute(
                        name: $runtimeName,
                        command: $commands,
                        output: $output,
                        timeout: $timeout
                    );

                    if (!$status) {
                        throw new Exception('Failed to create runtime: ' . $output, 400);
                    }
                } catch (Throwable $err) {
                    throw new Exception($err->getMessage(), 400);
                }
            }

            /**
             * Move built code to expected build directory
             */
            if (!empty($destination)) {
                // Check if the build was successful by checking if file exists
                if (!$localDevice->exists($tmpBuild)) {
                    throw new Exception('Something went wrong when starting runtime.', 500);
                }

                $size = $localDevice->getFileSize($tmpBuild);
                $container['size'] = $size;

                $destinationDevice = $this->getStorageDevice($destination);
                $path = $destinationDevice->getPath(\uniqid() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));


                if (!$localDevice->transfer($tmpBuild, $path, $destinationDevice)) {
                    throw new Exception('Failed to move built code to storage', 500);
                };

                $container['path'] = $path;
            }

            if ($output === '') {
                $output = 'Runtime created successfully!';
            }

            $endTime = \microtime(true);
            $duration = $endTime - $startTime;

            $output = \mb_substr($output, -1000000); // Limit to 1MB

            $container = array_merge($container, [
                'output' => $output,
                'startTime' => $startTime,
                'duration' => $duration,
            ]);

            $activeRuntime = $this->activeRuntimes->get($runtimeName);
            $activeRuntime['updated'] = \microtime(true);
            $activeRuntime['status'] = 'Up ' . \round($duration, 2) . 's';
            $this->activeRuntimes->set($runtimeName, $activeRuntime);
        } catch (Throwable $th) {
            $message = !empty($output) ? $output : $th->getMessage();

            // Extract as much logs as we can
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
            } catch (Throwable $err) {
                // Ignore, use fallback error message
            }

            if ($remove) {
                \sleep(2); // Allow time to read logs
            }

            $localDevice->deletePath($tmpFolder);

            // Silently try to kill container
            try {
                $this->orchestration->remove($runtimeName, true);
            } catch (Throwable $th) {
            }

            $this->activeRuntimes->del($runtimeName);

            $message = \mb_substr($message, -1000000); // Limit to 1MB

            throw new Exception($message, $th->getCode() ?: 500);
        }

        // Container cleanup
        if ($remove) {
            \sleep(2); // Allow time to read logs

            $localDevice->deletePath($tmpFolder);

            // Silently try to kill container
            try {
                $this->orchestration->remove($runtimeName, true);
            } catch (Throwable $th) {
            }

            $this->activeRuntimes->del($runtimeName);
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
        $log->addTag('runtimeId', $runtimeName);

        if (!$this->activeRuntimes->exists($runtimeName)) {
            throw new Exception('Runtime not found', 404);
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
        Log $log
    ): mixed {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $log->addTag('image', $image);
        $log->addTag('version', $version);
        $log->addTag('runtimeId', $runtimeName);

        $variables = \array_merge($variables, [
            'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
        ]);

        $prepareStart = \microtime(true);


        // Prepare runtime
        if (!$this->activeRuntimes->exists($runtimeName)) {
            if (empty($image) || empty($source)) {
                throw new Exception('Runtime not found. Please start it first or provide runtime-related parameters.', 401);
            }

            // Prepare request to executor
            $sendCreateRuntimeRequest = function () use ($runtimeId, $image, $source, $entrypoint, $variables, $cpus, $memory, $version, $restartPolicy, $runtimeEntrypoint) {
                $statusCode = 0;
                $errNo = -1;
                $executorResponse = '';

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
                    throw new Exception('Function timed out during preparation.', 400);
                }

                ['errNo' => $errNo, 'error' => $error, 'statusCode' => $statusCode, 'executorResponse' => $executorResponse] = \call_user_func($sendCreateRuntimeRequest);

                if ($errNo === 0) {
                    $body = \json_decode($executorResponse, true);

                    // If the runtime has not yet attempted to start, it will return 500
                    if ($statusCode >= 500) {
                        $error = $body['message'];

                    // If the runtime fails to start, it will return 400, except for 409
                    // which indicates that the runtime is already being created
                    } elseif ($statusCode >= 400 && $statusCode !== 409) {
                        $error = $body['message'];
                        throw new Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                    } else {
                        break;
                    }

                // Connection refused - see https://openswoole.com/docs/swoole-error-code
                } elseif ($errNo !== 111) {
                    throw new Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                }

                // Wait 0.5s and check again
                \usleep(500000);
            }
        }

        // Lower timeout by time it took to prepare container
        $timeout -= (\microtime(true) - $prepareStart);

        // Update swoole table
        $runtime = $this->activeRuntimes->get($runtimeName) ?? [];

        $log->addTag('image', $runtime['image']);

        $runtime['updated'] = \time();
        $this->activeRuntimes->set($runtimeName, $runtime);

        // Ensure runtime started
        $launchStart = \microtime(true);
        while (true) {
            // If timeout is passed, stop and return error
            if (\microtime(true) - $launchStart >= $timeout) {
                throw new Exception('Function timed out during launch.', 400);
            }

            if ($this->activeRuntimes->get($runtimeName)['status'] !== 'pending') {
                break;
            }

            // Wait 0.5s and check again
            \usleep(500000);
        }

        // Lower timeout by time it took to launch container
        $timeout -= (\microtime(true) - $launchStart);

        // Ensure we have secret
        $runtime = $this->activeRuntimes->get($runtimeName);
        $hostname = $runtime['hostname'];
        $secret = $runtime['key'];
        if (empty($secret)) {
            throw new Exception('Runtime secret not found. Please re-create the runtime.', 500);
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

        $executeV4 = function () use ($path, $method, $headers, $payload, $secret, $hostname, $timeout, $runtimeName, $logging): array {
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
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) { // ignore invalid headers
                    return $len;
                }

                $key = strtolower(trim($header[0]));
                $responseHeaders[$key] = trim($header[1]);

                if (\in_array($key, ['x-open-runtimes-log-id'])) {
                    $responseHeaders[$key] = \urldecode($responseHeaders[$key]);
                }

                return $len;
            });

            \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout + 5); // Gives extra 5s after safe timeout to recieve response
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            if ($logging == true) {
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
                        $logs .= "\nLog file has been truncated to 5MB.";
                    } else {
                        $logs = $logDevice->read($logFile);
                    }

                    $logDevice->delete($logFile);
                }

                if ($logDevice->exists($errorFile)) {
                    if ($logDevice->getFileSize($errorFile) > MAX_LOG_SIZE) {
                        $maxToRead = MAX_LOG_SIZE;
                        $errors = $logDevice->read($errorFile, 0, $maxToRead);
                        $errors .= "\nError file has been truncated to 5MB.";
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
                    throw new Exception('Function timed out during cold start.', 400);
                }

                $online = $validator->isValid($hostname . ':' . 3000);
                if ($online) {
                    break;
                }

                // Wait 0.5s and check again
                \usleep(500000);
            }

            // Update swoole table
            $runtime = $this->activeRuntimes->get($runtimeName);
            $runtime['listening'] = 1;
            $this->activeRuntimes->set($runtimeName, $runtime);

            // Lower timeout by time it took to cold-start
            $timeout -= (\microtime(true) - $pingStart);
        }

        // Execute function
        $executionRequest = $version === 'v4' ? $executeV4 : $executeV2;

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
            $log->addExtra('activeRuntime', $this->activeRuntimes->get($runtimeName));
            $log->addExtra('error', $executionResponse['error']);
            $log->addTag('hostname', $hostname);

            // Intended timeout error for v2 functions
            if ($version === 'v2' && $executionResponse['errNo'] === SOCKET_ETIMEDOUT) {
                throw new Exception($executionResponse['error'], 400);
            }

            throw new Exception('Internal curl error has occurred within the executor! Error Number: ' . $executionResponse['errNo'], 500);
        }

        // Successful execution
        ['statusCode' => $statusCode, 'body' => $body, 'logs' => $logs, 'errors' => $errors, 'headers' => $headers] = $executionResponse;

        $endTime = \microtime(true);
        $duration = $endTime - $startTime;

        if ($version === 'v2') {
            $logs = \mb_strcut($logs, 0, 1000000);
            $errors = \mb_strcut($errors, 0, 1000000);
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
                    } catch (Exception $e) {
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
                } catch (Exception $e) {
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
            throw new Exception('Runtime not found', 404);
        }

        return $this->activeRuntimes->get($name);
    }

    public function getStats(): Stats
    {
        return $this->stats;
    }
}
