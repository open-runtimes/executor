<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Runtimes\Runtimes;
use OpenRuntimes\Executor\BodyMultipart;
use OpenRuntimes\Executor\Validator\TCP;
use OpenRuntimes\Executor\Usage;
use Swoole\Process;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\CLI\Console;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Orchestration\Orchestration;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\DSN\DSN;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Route;
use Utopia\Http\Validator\AnyOf;
use Utopia\Http\Validator\Assoc;
use Utopia\Http\Validator\Boolean;
use Utopia\Http\Validator\FloatValidator;
use Utopia\Http\Validator\Integer;
use Utopia\Http\Validator\Text;
use Utopia\Http\Validator\WhiteList;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Registry\Registry;

use function Swoole\Coroutine\batch;
use function Swoole\Coroutine\run;

// Unlimited memory limit to handle as many coroutines/requests as possible
ini_set('memory_limit', '-1');

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

Http::setMode((string)Http::getEnv('OPR_EXECUTOR_ENV', Http::MODE_TYPE_PRODUCTION));

const MAX_LOG_SIZE = 5 * 1024 * 1024;

// Setup Registry
$register = new Registry();

/**
 * Create logger for cloud logging
 */
$register->set('logger', function () {
    $providerName = Http::getEnv('OPR_EXECUTOR_LOGGING_PROVIDER', '');
    $providerConfig = Http::getEnv('OPR_EXECUTOR_LOGGING_CONFIG', '');

    try {
        $loggingProvider = new DSN($providerConfig ?? '');

        $providerName = $loggingProvider->getScheme();
        $providerConfig = match ($providerName) {
            'sentry' => ['key' => $loggingProvider->getPassword(), 'projectId' => $loggingProvider->getUser() ?? '', 'host' => $loggingProvider->getHost()],
            'logowl' => ['ticket' => $loggingProvider->getUser() ?? '', 'host' => $loggingProvider->getHost()],
            default => ['key' => $loggingProvider->getHost()],
        };
    } catch (Throwable) {
        $configChunks = \explode(";", ($providerConfig ?? ''));

        $providerConfig = match ($providerName) {
            'sentry' => ['key' => $configChunks[0], 'projectId' => $configChunks[1] ?? '', 'host' => '',],
            'logowl' => ['ticket' => $configChunks[0] ?? '', 'host' => ''],
            default => ['key' => $providerConfig],
        };
    }

    $logger = null;

    if (!empty($providerName) && is_array($providerConfig) && Logger::hasProvider($providerName)) {
        $adapter = match ($providerName) {
            'sentry' => new Sentry($providerConfig['projectId'] ?? '', $providerConfig['key'] ?? '', $providerConfig['host'] ?? ''),
            'logowl' => new LogOwl($providerConfig['ticket'] ?? '', $providerConfig['host'] ?? ''),
            'raygun' => new Raygun($providerConfig['key'] ?? ''),
            'appsignal' => new AppSignal($providerConfig['key'] ?? ''),
            default => throw new Exception('Provider "' . $providerName . '" not supported.')
        };

        $logger = new Logger($adapter);
    }

    return $logger;
});

/**
 * Create orchestration
 */
$register->set('orchestration', function () {
    $dockerUser = (string) Http::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', '');
    $dockerPass = (string) Http::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '');
    $orchestration = new Orchestration(new DockerAPI($dockerUser, $dockerPass));

    return $orchestration;
});

/**
 * Create a Swoole table to store runtime information
 */
$register->set('activeRuntimes', function () {
    $table = new Table(4096);

    $table->column('created', Table::TYPE_FLOAT);
    $table->column('updated', Table::TYPE_FLOAT);
    $table->column('name', Table::TYPE_STRING, 1024);
    $table->column('hostname', Table::TYPE_STRING, 1024);
    $table->column('status', Table::TYPE_STRING, 256);
    $table->column('key', Table::TYPE_STRING, 1024);
    $table->column('listening', Table::TYPE_INT, 1);
    $table->create();

    return $table;
});

/**
 * Create a Swoole table of usage stats (separate for host and containers)
 */
$register->set('statsContainers', function () {
    $table = new Table(1024);

    $table->column('usage', Table::TYPE_FLOAT, 8);
    $table->create();

    return $table;
});

$register->set('statsHost', function () {
    $table = new Table(1024);

    $table->column('usage', Table::TYPE_FLOAT, 8);
    $table->create();

    return $table;
});

/** Set Resources */
Http::setResource('register', fn () => $register);
Http::setResource('orchestration', fn (Registry $register) => $register->get('orchestration'), ['register']);
Http::setResource('activeRuntimes', fn (Registry $register) => $register->get('activeRuntimes'), ['register']);
Http::setResource('logger', fn (Registry $register) => $register->get('logger'), ['register']);
Http::setResource('statsContainers', fn (Registry $register) => $register->get('statsContainers'), ['register']);
Http::setResource('statsHost', fn (Registry $register) => $register->get('statsHost'), ['register']);

Http::setResource('log', fn () => new Log());

function logError(Log $log, Throwable $error, string $action, Logger $logger = null, Route $route = null): void
{
    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());

    if ($logger && ($error->getCode() === 500 || $error->getCode() === 0)) {
        $version = (string)Http::getEnv('OPR_EXECUTOR_VERSION', '');
        if (empty($version)) {
            $version = 'UNKNOWN';
        }

        $log->setNamespace("executor");
        $log->setServer(\gethostname() !== false ? \gethostname() : null);
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        if ($route) {
            $log->addTag('method', $route->getMethod());
            $log->addTag('url', $route->getPath());
        }

        $log->addTag('code', \strval($error->getCode()));
        $log->addTag('verboseType', get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());
        // TODO: @Meldiron Uncomment, was warning: Undefined array key "file" in Sentry.php on line 68
        // $log->addExtra('detailedTrace', $error->getTrace());

        $log->setAction($action);

        $log->setEnvironment(Http::isProduction() ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Executor log pushed with status code: ' . $responseCode);
    }
}

function getStorageDevice(string $root): Device
{
    $connection = Http::getEnv('OPR_EXECUTOR_CONNECTION_STORAGE', '');

    if (!empty($connection)) {
        $acl = 'private';
        $device = Storage::DEVICE_LOCAL;
        $accessKey = '';
        $accessSecret = '';
        $bucket = '';
        $region = '';

        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
            $accessKey = $dsn->getUser() ?? '';
            $accessSecret = $dsn->getPassword() ?? '';
            $bucket = $dsn->getPath() ?? '';
            $region = $dsn->getParam('region');
        } catch (\Exception $e) {
            Console::warning($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
        }

        switch ($device) {
            case Storage::DEVICE_S3:
                return new S3($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case STORAGE::DEVICE_DO_SPACES:
                return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_BACKBLAZE:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LINODE:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_WASABI:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    } else {
        switch (strtolower(Http::getEnv('OPR_EXECUTOR_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? '')) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
            case Storage::DEVICE_S3:
                $s3AccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_ACCESS_KEY', '') ?? '';
                $s3SecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_SECRET', '') ?? '';
                $s3Region = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_REGION', '') ?? '';
                $s3Bucket = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_BUCKET', '') ?? '';
                $s3Acl = 'private';
                return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_ACCESS_KEY', '') ?? '';
                $doSpacesSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_SECRET', '') ?? '';
                $doSpacesRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_REGION', '') ?? '';
                $doSpacesBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_BUCKET', '') ?? '';
                $doSpacesAcl = 'private';
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_ACCESS_KEY', '') ?? '';
                $backblazeSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_SECRET', '') ?? '';
                $backblazeRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_REGION', '') ?? '';
                $backblazeBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_BUCKET', '') ?? '';
                $backblazeAcl = 'private';
                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
            case Storage::DEVICE_LINODE:
                $linodeAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_ACCESS_KEY', '') ?? '';
                $linodeSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_SECRET', '') ?? '';
                $linodeRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_REGION', '') ?? '';
                $linodeBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_BUCKET', '') ?? '';
                $linodeAcl = 'private';
                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_ACCESS_KEY', '') ?? '';
                $wasabiSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_SECRET', '') ?? '';
                $wasabiRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_REGION', '') ?? '';
                $wasabiBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_BUCKET', '') ?? '';
                $wasabiAcl = 'private';
                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}

function removeAllRuntimes(Table $activeRuntimes, Orchestration $orchestration): void
{
    Console::log('Cleaning up containers...');

    $functionsToRemove = $orchestration->list(['label' => 'openruntimes-executor=' . System::getHostname()]);

    if (\count($functionsToRemove) === 0) {
        Console::info('No containers found to clean up.');
    }

    $callables = [];

    foreach ($functionsToRemove as $container) {
        $callables[] = function () use ($container, $activeRuntimes, $orchestration) {
            try {
                $orchestration->remove($container->getId(), true);

                $activeRuntimeId = $container->getName();

                if (!$activeRuntimes->exists($activeRuntimeId)) {
                    $activeRuntimes->del($activeRuntimeId);
                }

                Console::success('Removed container ' . $container->getName());
            } catch (\Throwable $th) {
                Console::error('Failed to remove container: ' . $container->getName());
                Console::error($th);
            }
        };
    }

    batch($callables);

    Console::success('Cleanup finished.');
}

Http::get('/v1/runtimes/:runtimeId/logs')
    ->desc("Get live stream of logs of a runtime")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->param('timeout', '600', new Text(16), 'Maximum logs timeout.', true)
    ->inject('response')
    ->inject('log')
    ->action(function (string $runtimeId, string $timeoutStr, Response $response, Log $log) {
        $timeout = \intval($timeoutStr);

        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $response->sendHeader('Content-Type', 'text/event-stream');
        $response->sendHeader('Cache-Control', 'no-cache');

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

        $response->end();
    });

Http::post('/v1/runtimes')
    ->desc("Create a new runtime server")
    ->param('runtimeId', '', new Text(64), 'Unique runtime ID.')
    ->param('image', '', new Text(128), 'Base image name of the runtime.')
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file.', true)
    ->param('source', '', new Text(0), 'Path to source files.', true)
    ->param('destination', '', new Text(0), 'Destination folder to store runtime files into.', true)
    ->param('variables', [], new Assoc(), 'Environment variables passed into runtime.', true)
    ->param('runtimeEntrypoint', '', new Text(1024, 0), 'Commands to run when creating a container. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('command', '', new Text(1024), 'Commands to run after container is created. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('timeout', 600, new Integer(), 'Commands execution time in seconds.', true)
    ->param('remove', false, new Boolean(), 'Remove a runtime after execution.', true)
    ->param('cpus', 1, new FloatValidator(), 'Container CPU.', true)
    ->param('memory', 512, new Integer(), 'Comtainer RAM memory.', true)
    ->param('version', 'v4', new WhiteList(['v2', 'v4']), 'Runtime Open Runtime version.', true)
    ->param('restartPolicy', DockerAPI::RESTART_NO, new WhiteList([DockerAPI::RESTART_NO, DockerAPI::RESTART_ALWAYS, DockerAPI::RESTART_ON_FAILURE, DockerAPI::RESTART_UNLESS_STOPPED], true), 'Define restart policy for the runtime once an exit code is returned. Default value is "no". Possible values are "no", "always", "on-failure", "unless-stopped".', true)
    ->inject('orchestration')
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('log')
    ->action(function (string $runtimeId, string $image, string $entrypoint, string $source, string $destination, array $variables, string $runtimeEntrypoint, string $command, int $timeout, bool $remove, float $cpus, int $memory, string $version, string $restartPolicy, Orchestration $orchestration, Table $activeRuntimes, Response $response, Log $log) {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $runtimeHostname = \uniqid();

        $log->addTag('version', $version);
        $log->addTag('runtimeId', $runtimeName);

        if ($activeRuntimes->exists($runtimeName)) {
            if ($activeRuntimes->get($runtimeName)['status'] == 'pending') {
                throw new \Exception('A runtime with the same ID is already being created. Attempt a execution soon.', 409);
            }

            throw new Exception('Runtime already exists.', 409);
        }

        $container = [];
        $output = '';
        $startTime = \microtime(true);

        $secret = \bin2hex(\random_bytes(16));

        $activeRuntimes->set($runtimeName, [
            'listening' => 0,
            'name' => $runtimeName,
            'hostname' => $runtimeHostname,
            'created' => $startTime,
            'updated' => $startTime,
            'status' => 'pending',
            'key' => $secret,
        ]);

        /**
         * Temporary file paths in the executor
         */
        $tmpFolder = "tmp/$runtimeName/";
        $tmpSource = "/{$tmpFolder}src/code.tar.gz";
        $tmpBuild = "/{$tmpFolder}builds/code.tar.gz";
        $tmpLogs = "/{$tmpFolder}logs";

        $sourceDevice = getStorageDevice("/");
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

            /**
             * Create container
             */
            $variables = \array_merge($variables, match ($version) {
                'v2' => [
                    'INTERNAL_RUNTIME_KEY' => $secret,
                    'INTERNAL_RUNTIME_ENTRYPOINT' => $entrypoint,
                    'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
                ],
                'v4' => [
                    'OPEN_RUNTIMES_SECRET' => $secret,
                    'OPEN_RUNTIMES_ENTRYPOINT' => $entrypoint,
                    'OPEN_RUNTIMES_HOSTNAME' => System::getHostname()
                ]
            });

            $variables = array_map(fn ($v) => strval($v), $variables);
            $orchestration
                ->setCpus($cpus)
                ->setMemory($memory);

            $runtimeEntrypointCommands = [];

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

            $openruntimes_networks = explode(',', str_replace(' ', '', Http::getEnv('OPR_EXECUTOR_NETWORK') ?: 'executor_runtimes'));
            $openruntimes_network = $openruntimes_networks[array_rand($openruntimes_networks)];

            $volumes = [
                \dirname($tmpSource) . ':/tmp:rw',
                \dirname($tmpBuild) . ':' . $codeMountPath . ':rw',
            ];

            if ($version === 'v4') {
                $volumes[] = \dirname($tmpLogs . '/logs') . ':/mnt/logs:rw';
            }

            /** Keep the container alive if we have commands to be executed */
            $containerId = $orchestration->run(
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
                network: \strval($openruntimes_network) ?: 'executor_runtimes',
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
                    $status = $orchestration->execute(
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

                $destinationDevice = getStorageDevice($destination);
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

            $container = array_merge($container, [
                'output' => \mb_strcut($output, 0, 1000000), // Limit to 1MB
                'startTime' => $startTime,
                'duration' => $duration,
            ]);

            $activeRuntime = $activeRuntimes->get($runtimeName);
            $activeRuntime['updated'] = \microtime(true);
            $activeRuntime['status'] = 'Up ' . \round($duration, 2) . 's';
            $activeRuntimes->set($runtimeName, $activeRuntime);
        } catch (Throwable $th) {
            $message = !empty($output) ? $output : $th->getMessage();

            // Extract as much logs as we can
            try {
                $logs = '';
                $status = $orchestration->execute(
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
                $orchestration->remove($runtimeName, true);
            } catch (Throwable $th) {
            }

            $activeRuntimes->del($runtimeName);

            throw new Exception($message, $th->getCode() ?: 500);
        }

        // Container cleanup
        if ($remove) {
            \sleep(2); // Allow time to read logs

            $localDevice->deletePath($tmpFolder);

            // Silently try to kill container
            try {
                $orchestration->remove($runtimeName, true);
            } catch (Throwable $th) {
            }

            $activeRuntimes->del($runtimeName);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($container);
    });

Http::get('/v1/runtimes')
    ->desc("List currently active runtimes")
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (Table $activeRuntimes, Response $response) {
        $runtimes = [];

        foreach ($activeRuntimes as $runtime) {
            $runtimes[] = $runtime;
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($runtimes);
    });

Http::get('/v1/runtimes/:runtimeId')
    ->desc("Get a runtime by its ID")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('log')
    ->action(function (string $runtimeId, Table $activeRuntimes, Response $response, Log $log) {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $log->addTag('runtimeId', $runtimeName);

        if (!$activeRuntimes->exists($runtimeName)) {
            throw new Exception('Runtime not found', 404);
        }

        $runtime = $activeRuntimes->get($runtimeName);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($runtime);
    });

Http::delete('/v1/runtimes/:runtimeId')
    ->desc('Delete a runtime')
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.', false)
    ->inject('orchestration')
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('log')
    ->action(function (string $runtimeId, Orchestration $orchestration, Table $activeRuntimes, Response $response, Log $log) {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $log->addTag('runtimeId', $runtimeName);

        if (!$activeRuntimes->exists($runtimeName)) {
            throw new Exception('Runtime not found', 404);
        }

        $orchestration->remove($runtimeName, true);
        $activeRuntimes->del($runtimeName);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });

Http::post('/v1/runtimes/:runtimeId/executions')
    ->alias('/v1/runtimes/:runtimeId/execution')
    ->desc('Create an execution')
    // Execution-related
    ->param('runtimeId', '', new Text(64), 'The runtimeID to execute.')
    ->param('body', '', new Text(20971520), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('path', '/', new Text(2048), 'Path from which execution comes.', true)
    ->param('method', 'GET', new Whitelist(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true), 'Path from which execution comes.', true)
    ->param('headers', [], new AnyOf([new Text(65535), new Assoc()], AnyOf::TYPE_MIXED), 'Headers passed into runtime.', true)
    ->param('timeout', 15, new Integer(true), 'Function maximum execution time in seconds.', true)
    // Runtime-related
    ->param('image', '', new Text(128), 'Base image name of the runtime.', true)
    ->param('source', '', new Text(0), 'Path to source files.', true)
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file.', true)
    ->param('variables', [], new AnyOf([new Text(65535), new Assoc()], AnyOf::TYPE_MIXED), 'Environment variables passed into runtime.', true)
    ->param('cpus', 1, new FloatValidator(true), 'Container CPU.', true)
    ->param('memory', 512, new Integer(true), 'Container RAM memory.', true)
    ->param('version', 'v4', new WhiteList(['v2', 'v4']), 'Runtime Open Runtime version.', true)
    ->param('runtimeEntrypoint', '', new Text(1024, 0), 'Commands to run when creating a container. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('logging', true, new Boolean(true), 'Whether executions will be logged.', true)
    ->param('restartPolicy', DockerAPI::RESTART_NO, new WhiteList([DockerAPI::RESTART_NO, DockerAPI::RESTART_ALWAYS, DockerAPI::RESTART_ON_FAILURE, DockerAPI::RESTART_UNLESS_STOPPED], true), 'Define restart policy once exit code is returned by command. Default value is "no". Possible values are "no", "always", "on-failure", "unless-stopped".', true)
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('request')
    ->inject('log')
    ->action(
        function (string $runtimeId, ?string $payload, string $path, string $method, mixed $headers, int $timeout, string $image, string $source, string $entrypoint, mixed $variables, float $cpus, int $memory, string $version, string $runtimeEntrypoint, bool $logging, string $restartPolicy, Table $activeRuntimes, Response $response, Request $request, Log $log) {
            if (empty($payload)) {
                $payload = '';
            }

            // Extra parsers and validators to support both JSON and multipart
            $intParams = ['timeout', 'memory'];
            foreach ($intParams as $intParam) {
                if (!empty($$intParam) && !is_numeric($$intParam)) {
                    $$intParam = \intval($$intParam);
                }
            }

            $floatParams = ['cpus'];
            foreach ($floatParams as $floatPram) {
                if (!empty($$floatPram) && !is_numeric($$floatPram)) {
                    $$floatPram = \floatval($$floatPram);
                }
            }

            /**
             * @var array<string, mixed> $headers
             * @var array<string, mixed> $variables
             */
            $assocParams = ['headers', 'variables'];
            foreach ($assocParams as $assocParam) {
                if (!empty($$assocParam) && !is_array($$assocParam)) {
                    $$assocParam = \json_decode($$assocParam, true);
                }
            }

            $booleanParams = ['logging'];
            foreach ($booleanParams as $booleamParam) {
                if (!empty($$booleamParam) && !is_bool($$booleamParam)) {
                    $$booleamParam = $$booleamParam === "true" ? true : false;
                }
            }

            // 'headers' validator
            $validator = new Assoc();
            if (!$validator->isValid($headers)) {
                throw new Exception($validator->getDescription(), 400);
            }

            // 'variables' validator
            $validator = new Assoc();
            if (!$validator->isValid($variables)) {
                throw new Exception($validator->getDescription(), 400);
            }

            $runtimeName = System::getHostname() . '-' . $runtimeId;

            $log->addTag('version', $version);
            $log->addTag('runtimeId', $runtimeName);

            $variables = \array_merge($variables, [
                'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
            ]);

            $prepareStart = \microtime(true);


            // Prepare runtime
            if (!$activeRuntimes->exists($runtimeName)) {
                if (empty($image) || empty($source) || empty($entrypoint)) {
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

                        if ($statusCode >= 500) {
                            $error = $body['message'];
                        // Continues to retry logic
                        } elseif ($statusCode >= 400) {
                            $error = $body['message'];
                            throw new Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                        } else {
                            break;
                        }
                    } elseif ($errNo !== 111) { // Connection Refused - see https://openswoole.com/docs/swoole-error-code
                        throw new Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                    }

                    // Wait 0.5s and check again
                    \usleep(500000);
                }
            }

            // Lower timeout by time it took to prepare container
            $timeout -= (\microtime(true) - $prepareStart);

            // Update swoole table
            $runtime = $activeRuntimes->get($runtimeName) ?? [];
            $runtime['updated'] = \time();
            $activeRuntimes->set($runtimeName, $runtime);

            // Ensure runtime started
            $launchStart = \microtime(true);
            while (true) {
                // If timeout is passed, stop and return error
                if (\microtime(true) - $launchStart >= $timeout) {
                    throw new Exception('Function timed out during launch.', 400);
                }

                if ($activeRuntimes->get($runtimeName)['status'] !== 'pending') {
                    break;
                }

                // Wait 0.5s and check again
                \usleep(500000);
            }

            // Lower timeout by time it took to launch container
            $timeout -= (\microtime(true) - $launchStart);

            // Ensure we have secret
            $runtime = $activeRuntimes->get($runtimeName);
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
                    $logFile = '/tmp/'.$runtimeName .'/logs/' . $fileId . '_logs.log';
                    $errorFile = '/tmp/'.$runtimeName .'/logs/' . $fileId . '_errors.log';

                    $logDevice = getStorageDevice("/");

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
                $runtime = $activeRuntimes->get($runtimeName);
                $runtime['listening'] = 1;
                $activeRuntimes->set($runtimeName, $runtime);

                // Lower timeout by time it took to cold-start
                $timeout -= (\microtime(true) - $pingStart);
            }

            // Execute function
            $executionRequest = $version === 'v4' ? $executeV4 : $executeV2;
            $executionResponse = \call_user_func($executionRequest);

            // Error occured
            if ($executionResponse['errNo'] !== 0) {
                // Intended timeout error for v2 functions
                if ($executionResponse['errNo'] === 110 && $version === 'v2') {
                    throw new Exception($executionResponse['error'], 400);
                }

                // Unknown error
                throw new Exception('Internal curl errors has occurred within the executor! Error Number: ' . $executionResponse['errNo'] . '. Error Msg: ' . $executionResponse['error'], 500);
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

            $execution['body'] = $body;

            // Update swoole table
            $runtime = $activeRuntimes->get($runtimeName);
            $runtime['updated'] = \microtime(true);
            $activeRuntimes->set($runtimeName, $runtime);

            $acceptTypes = \explode(', ', $request->getHeader('accept', 'multipart/form-data'));
            $isJson = false;

            foreach ($acceptTypes as $acceptType) {
                if (\str_starts_with($acceptType, 'application/json') || \str_starts_with($acceptType, 'application/*')) {
                    $isJson = true;
                    break;
                }
            }

            if ($isJson) {
                $executionString = \json_encode($execution, JSON_UNESCAPED_UNICODE);
                if (!$executionString) {
                    throw new Exception('Execution resulted in binary response, but JSON response does not allow binaries. Use "Accept: multipart/form-data" header to support binaries.', 400);
                }

                $response
                    ->setStatusCode(Response::STATUS_CODE_OK)
                    ->addHeader('content-type', 'application/json')
                    ->send($executionString);
            } else {
                // Multipart form data response

                $multipart = new BodyMultipart();
                foreach ($execution as $key => $value) {
                    $multipart->setPart($key, $value);
                }

                $response
                    ->setStatusCode(Response::STATUS_CODE_OK)
                    ->addHeader('content-type', $multipart->exportHeader())
                    ->send($multipart->exportBody());
            }
        }
    );

Http::get('/v1/health')
    ->desc("Get health status of host machine and runtimes.")
    ->inject('statsHost')
    ->inject('statsContainers')
    ->inject('response')
    ->action(function (Table $statsHost, Table $statsContainers, Response $response) {
        $output = [
            'status' => 'pass',
            'runtimes' => []
        ];

        $hostUsage = $statsHost->get('host', 'usage') ?? null;
        $output['usage'] = $hostUsage;

        foreach ($statsContainers as $hostname => $stat) {
            $output['runtimes'][$hostname] = [
                'status' => 'pass',
                'usage' => $stat['usage'] ?? null
            ];
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($output);
    });

/** Set callbacks */
Http::error()
    ->inject('route')
    ->inject('error')
    ->inject('logger')
    ->inject('response')
    ->inject('log')
    ->action(function (?Route $route, Throwable $error, ?Logger $logger, Response $response, Log $log) {
        logError($log, $error, "httpError", $logger, $route);

        switch ($error->getCode()) {
            case 400: // Error allowed publicly
            case 401: // Error allowed publicly
            case 402: // Error allowed publicly
            case 403: // Error allowed publicly
            case 404: // Error allowed publicly
            case 406: // Error allowed publicly
            case 409: // Error allowed publicly
            case 412: // Error allowed publicly
            case 425: // Error allowed publicly
            case 429: // Error allowed publicly
            case 501: // Error allowed publicly
            case 503: // Error allowed publicly
                $code = $error->getCode();
                break;
            default:
                $code = 500; // All other errors get the generic 500 server error status code
        }

        $output = [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTrace(),
            'version' => Http::getEnv('OPR_EXECUTOR_VERSION', 'UNKNOWN')
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($code);

        $response->json($output);
    });

Http::init()
    ->inject('request')
    ->action(function (Request $request) {
        $secretKey = \explode(' ', $request->getHeader('authorization', ''))[1] ?? '';
        if (empty($secretKey) || $secretKey !== Http::getEnv('OPR_EXECUTOR_SECRET', '')) {
            throw new Exception('Missing executor key', 401);
        }
    });

run(function () use ($register) {
    $orchestration = $register->get('orchestration');
    $statsContainers = $register->get('statsContainers');
    $activeRuntimes = $register->get('activeRuntimes');
    $statsHost = $register->get('statsHost');

    /**
     * Remove residual runtimes
     */
    Console::info('Removing orphan runtimes...');

    removeAllRuntimes($activeRuntimes, $orchestration);

    Console::success("Orphan runtimes removal finished.");

    /**
     * Warmup: make sure images are ready to run fast 🚀
     */
    $allowList = empty(Http::getEnv('OPR_EXECUTOR_RUNTIMES')) ? [] : \explode(',', Http::getEnv('OPR_EXECUTOR_RUNTIMES'));

    $runtimeVersions = \explode(',', Http::getEnv('OPR_EXECUTOR_RUNTIME_VERSIONS', 'v4') ?? 'v4');
    foreach ($runtimeVersions as $runtimeVersion) {
        Console::success("Pulling $runtimeVersion images...");
        $runtimes = new Runtimes($runtimeVersion); // TODO: @Meldiron Make part of open runtimes
        $runtimes = $runtimes->getAll(true, $allowList);
        $callables = [];
        foreach ($runtimes as $runtime) {
            $callables[] = function () use ($runtime, $orchestration) {
                Console::log('Warming up ' . $runtime['name'] . ' ' . $runtime['version'] . ' environment...');
                $response = $orchestration->pull($runtime['image']);
                if ($response) {
                    Console::info("Successfully Warmed up {$runtime['name']} {$runtime['version']}!");
                } else {
                    Console::warning("Failed to Warmup {$runtime['name']} {$runtime['version']}!");
                }
            };
        }

        batch($callables);
    }

    Console::success("Image pulling finished.");

    /**
     * Run a maintenance worker every X seconds to remove inactive runtimes
     */
    Console::info('Starting maintenance interval...');
    $interval = (int)Http::getEnv('OPR_EXECUTOR_MAINTENANCE_INTERVAL', '3600'); // In seconds
    Timer::tick($interval * 1000, function () use ($orchestration, $activeRuntimes) {
        Console::info("Running maintenance task ...");
        // Stop idling runtimes
        foreach ($activeRuntimes as $runtimeName => $runtime) {
            $inactiveThreshold = \time() - \intval(Http::getEnv('OPR_EXECUTOR_INACTIVE_TRESHOLD', '60'));
            if ($runtime['updated'] < $inactiveThreshold) {
                go(function () use ($runtimeName, $runtime, $orchestration, $activeRuntimes) {
                    try {
                        $orchestration->remove($runtime['name'], true);
                        Console::success("Successfully removed {$runtime['name']}");
                    } catch (\Throwable $th) {
                        Console::error('Inactive Runtime deletion failed: ' . $th->getMessage());
                    } finally {
                        $activeRuntimes->del($runtimeName);
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

                foreach ($activeRuntimes as $runtimeName => $runtime) {
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
    function getStats(Table $statsHost, Table $statsContainers, Orchestration $orchestration, bool $recursive = false): void
    {
        // Get usage stats
        $usage = new Usage($orchestration);
        $usage->run();

        // Update host usage stats
        if ($usage->getHostUsage() !== null) {
            $oldStat = $statsHost->get('host', 'usage') ?? null;

            if ($oldStat === null) {
                $stat = $usage->getHostUsage();
            } else {
                $stat = ($oldStat + $usage->getHostUsage()) / 2;
            }

            $statsHost->set('host', ['usage' => $stat]);
        }

        // Update runtime usage stats
        foreach ($usage->getRuntimesUsage() as $runtime => $usageStat) {
            $oldStat = $statsContainers->get($runtime, 'usage') ?? null;

            if ($oldStat === null) {
                $stat = $usageStat;
            } else {
                $stat = ($oldStat + $usageStat) / 2;
            }

            $statsContainers->set($runtime, ['usage' => $stat]);
        }

        // Delete gone runtimes
        $runtimes = \array_keys($usage->getRuntimesUsage());
        foreach ($statsContainers as $hostname => $stat) {
            if (!(\in_array($hostname, $runtimes))) {
                $statsContainers->delete($hostname);
            }
        }

        if ($recursive) {
            Timer::after(1000, fn () => getStats($statsHost, $statsContainers, $orchestration, $recursive));
        }
    }

    // Load initial stats in blocking way
    getStats($statsHost, $statsContainers, $orchestration);

    // Setup infinite recurssion in non-blocking way
    \go(function () use ($statsHost, $statsContainers, $orchestration) {
        getStats($statsHost, $statsContainers, $orchestration, true);
    });

    Console::success('Stats interval started.');

    $server = new Server('0.0.0.0', '80');
    $http = new Http($server, 'UTC');

    Console::success('Executor is ready.');

    Process::signal(SIGINT, fn () => removeAllRuntimes($activeRuntimes, $orchestration));
    Process::signal(SIGQUIT, fn () => removeAllRuntimes($activeRuntimes, $orchestration));
    Process::signal(SIGKILL, fn () => removeAllRuntimes($activeRuntimes, $orchestration));
    Process::signal(SIGTERM, fn () => removeAllRuntimes($activeRuntimes, $orchestration));

    $http->start();
});
