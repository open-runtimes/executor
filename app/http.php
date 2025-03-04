<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Runtimes\Runtimes;
use OpenRuntimes\Executor\BodyMultipart;
use OpenRuntimes\Executor\Logs;
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
            'sentry' => ['key' => $loggingProvider->getPassword(), 'projectId' => $loggingProvider->getUser() ?? '', 'host' => 'https://' . $loggingProvider->getHost()],
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

    $table->column('version', Table::TYPE_STRING, 32);
    $table->column('created', Table::TYPE_FLOAT);
    $table->column('updated', Table::TYPE_FLOAT);
    $table->column('name', Table::TYPE_STRING, 1024);
    $table->column('hostname', Table::TYPE_STRING, 1024);
    $table->column('status', Table::TYPE_STRING, 256);
    $table->column('key', Table::TYPE_STRING, 1024);
    $table->column('listening', Table::TYPE_INT, 1);
    $table->column('image', Table::TYPE_STRING, 1024);
    $table->column('initialised', Table::TYPE_INT, 0);
    $table->create();

    return $table;
});

/**
 * Create a Swoole table of usage stats (separate for host and containers)
 */
$register->set('statsContainers', function () {
    $table = new Table(4096);

    $table->column('usage', Table::TYPE_FLOAT, 8);
    $table->create();

    return $table;
});

$register->set('statsHost', function () {
    $table = new Table(4096);

    $table->column('usage', Table::TYPE_FLOAT, 8);
    $table->create();

    return $table;
});


/** Set Resources */
Http::setResource('log', fn () => new Log());
Http::setResource('register', fn () => $register);
Http::setResource('orchestration', fn (Registry $register) => $register->get('orchestration'), ['register']);
Http::setResource('activeRuntimes', fn (Registry $register) => $register->get('activeRuntimes'), ['register']);
Http::setResource('logger', fn (Registry $register) => $register->get('logger'), ['register']);
Http::setResource('statsContainers', fn (Registry $register) => $register->get('statsContainers'), ['register']);
Http::setResource('statsHost', fn (Registry $register) => $register->get('statsHost'), ['register']);

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


/**
 * @param array<string> $networks
 *
 * @return array<string>
 */
function createNetworks(Orchestration $orchestration, array $networks): array
{
    $jobs = [];
    $createdNetworks = [];
    foreach ($networks as $network) {
        $jobs[] = function () use ($orchestration, $network, &$createdNetworks) {
            if (!$orchestration->networkExists($network)) {
                try {
                    $orchestration->createNetwork($network, false);
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
    $containers = $orchestration->list(['label' => "com.openruntimes.executor.image=$image"]);

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
                $orchestration->networkConnect($containerName, $network);
                Console::success("Successfully connected executor '$containerName' to network '$network'");
            } catch (Exception $e) {
                Console::error("Failed to connect executor '$containerName' to network '$network': " . $e->getMessage());
            }
        }
    }

    return $createdNetworks;
}

/**
 * @param array<string> $networks
 */
function cleanUp(Orchestration $orchestration, Table $activeRuntimes, array $networks = []): void
{
    Console::log('Cleaning up containers and networks...');

    $functionsToRemove = $orchestration->list(['label' => 'openruntimes-executor=' . System::getHostname()]);

    if (\count($functionsToRemove) === 0) {
        Console::info('No containers found to clean up.');
    }

    $jobsRuntimes = [];
    foreach ($functionsToRemove as $container) {
        $jobsRuntimes[] = function () use ($container, $activeRuntimes, $orchestration) {
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
    batch($jobsRuntimes);

    $jobsNetworks = [];
    foreach ($networks as $network) {
        $jobsNetworks[] = function () use ($orchestration, $network) {
            try {
                $orchestration->removeNetwork($network);
                Console::success("Removed network: $network");
            } catch (Exception $e) {
                Console::error("Failed to remove network $network: " . $e->getMessage());
            }
        };
    }
    batch($jobsNetworks);

    Console::success('Cleanup finished.');
}

Http::get('/v1/runtimes/:runtimeId/logs')
    ->desc("Get live stream of logs of a runtime")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->param('timeout', '600', new Text(16), 'Maximum logs timeout.', true)
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('log')
    ->inject('activeRuntimes')
    ->action(function (string $runtimeId, string $timeoutStr, Response $response, Log $log, Table $activeRuntimes) {
        $timeout = \intval($timeoutStr);

        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $log->addTag('runtimeId', $runtimeName);

        $response->sendHeader('Content-Type', 'text/event-stream');
        $response->sendHeader('Cache-Control', 'no-cache');

        $tmpFolder = "tmp/$runtimeName/";
        $tmpLogging = "/{$tmpFolder}logging"; // Build logs

        $version = null;
        $checkStart = \microtime(true);
        while (true) {
            if (\microtime(true) - $checkStart >= 10) { // Enforced timeout of 10s
                throw new Exception('Runtime was not created in time.', 400);
            }

            $runtime = $activeRuntimes->get($runtimeName);
            if (!empty($runtime)) {
                $version = $runtime['version'];
                break;
            }

            // Wait 0.5s and check again
            \usleep(500000);
        }

        if ($version === 'v2') {
            $response->end();
            return;
        }

        $checkStart = \microtime(true);
        while (true) {
            if (\microtime(true) - $checkStart >= $timeout) {
                throw new Exception('Log file was not created in time.', 400);
            }

            if (\file_exists($tmpLogging . '/logs.txt') && file_exists($tmpLogging . '/timings.txt')) {
                break;
            }

            // Ensure runtime is still present
            $runtime = $activeRuntimes->get($runtimeName);
            if (empty($runtime)) {
                $response->end();
                return;
            }

            // Wait 0.5s and check again
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
        $timerId = Timer::tick($streamInterval, function () use (&$logsProcess, &$logsChunk, $response, $activeRuntimes, $runtimeName) {
            $runtime = $activeRuntimes->get($runtimeName);
            if ($runtime['initialised'] === 1) {
                \proc_terminate($logsProcess, 9);
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

        $datetime = new DateTime("now", new DateTimeZone("UTC")); // Date used for tracking absolute log timing

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

        Timer::clear($timerId);
        $response->end();
    });

Http::post('/v1/runtimes/:runtimeId/commands')
    ->desc('Execute a command inside an existing runtime')
    ->param('runtimeId', '', new Text(64), 'Unique runtime ID.')
    ->param('command', '', new Text(1024), 'Command to execute.')
    ->param('timeout', 600, new Integer(), 'Commands execution time in seconds.', true)
    ->inject('orchestration')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, string $command, int $timeout, Orchestration $orchestration, Table $activeRuntimes, Response $response) {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        if (!$activeRuntimes->exists($runtimeName)) {
            throw new Exception('Runtime not found', 404);
        }

        $commands = [
            'sh',
            '-c',
            $command
        ];

        $output = '';
        $orchestration->execute($runtimeName, $commands, $output, [], $timeout);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json([ 'output' => $output ]);
    });

Http::post('/v1/runtimes')
    ->desc("Create a new runtime server")
    ->param('runtimeId', '', new Text(64), 'Unique runtime ID.')
    ->param('image', '', new Text(128), 'Base image name of the runtime.')
    ->param('entrypoint', '', new Text(256, 0), 'Entrypoint of the code file.', true)
    ->param('source', '', new Text(0), 'Path to source files.', true)
    ->param('destination', '', new Text(0), 'Destination folder to store runtime files into.', true)
    ->param('outputDirectory', '', new Text(0, 0), 'Path inside build to use as output. If empty, entire build is used.', true)
    ->param('variables', [], new Assoc(), 'Environment variables passed into runtime.', true)
    ->param('runtimeEntrypoint', '', new Text(1024, 0), 'Commands to run when creating a container. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('command', '', new Text(1024), 'Commands to run after container is created. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('timeout', 600, new Integer(), 'Commands execution time in seconds.', true)
    ->param('remove', false, new Boolean(), 'Remove a runtime after execution.', true)
    ->param('cpus', 1, new FloatValidator(true), 'Container CPU.', true)
    ->param('memory', 512, new Integer(), 'Container RAM memory.', true)
    ->param('version', 'v4', new WhiteList(['v2', 'v4']), 'Runtime Open Runtime version.', true)
    ->param('restartPolicy', DockerAPI::RESTART_NO, new WhiteList([DockerAPI::RESTART_NO, DockerAPI::RESTART_ALWAYS, DockerAPI::RESTART_ON_FAILURE, DockerAPI::RESTART_UNLESS_STOPPED], true), 'Define restart policy for the runtime once an exit code is returned. Default value is "no". Possible values are "no", "always", "on-failure", "unless-stopped".', true)
    ->inject('networks')
    ->inject('orchestration')
    ->inject('activeRuntimes')
    ->inject('response')
    ->inject('log')
    ->action(function (string $runtimeId, string $image, string $entrypoint, string $source, string $destination, string $outputDirectory, array $variables, string $runtimeEntrypoint, string $command, int $timeout, bool $remove, float $cpus, int $memory, string $version, string $restartPolicy, array $networks, Orchestration $orchestration, Table $activeRuntimes, Response $response, Log $log) {
        $runtimeName = System::getHostname() . '-' . $runtimeId;

        $runtimeHostname = \bin2hex(\random_bytes(16));

        $log->addTag('image', $image);
        $log->addTag('version', $version);
        $log->addTag('runtimeId', $runtimeName);

        if ($activeRuntimes->exists($runtimeName)) {
            if ($activeRuntimes->get($runtimeName)['status'] == 'pending') {
                throw new \Exception('A runtime with the same ID is already being created. Attempt a execution soon.', 409);
            }

            throw new Exception('Runtime already exists.', 409);
        }

        $container = [];
        $output = [];
        $startTime = \microtime(true);

        $secret = \bin2hex(\random_bytes(16));

        $activeRuntimes->set($runtimeName, [
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
        $tmpFolder = "tmp/$runtimeName/";
        $tmpSource = "/{$tmpFolder}src/code.tar.gz";
        $tmpBuild = "/{$tmpFolder}builds/code.tar.gz";
        $tmpLogging = "/{$tmpFolder}logging"; // Build logs
        $tmpLogs = "/{$tmpFolder}logs"; // Runtime logs

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
                    'OPEN_RUNTIMES_HOSTNAME' => System::getHostname(),
                    'OPEN_RUNTIMES_CPUS' => $cpus,
                    'OPEN_RUNTIMES_MEMORY' => $memory,
                ]
            });

            if (!empty($outputDirectory)) {
                $variables = \array_merge($variables, [
                    'OPEN_RUNTIMES_OUTPUT_DIRECTORY' => $outputDirectory
                ]);
            }

            $variables = \array_merge($variables, [
                'CI' => 'true'
            ]);

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

            $network = $networks[array_rand($networks)];

            $volumes = [
                \dirname($tmpSource) . ':/tmp:rw',
                \dirname($tmpBuild) . ':' . $codeMountPath . ':rw',
            ];

            if ($version === 'v4') {
                $volumes[] = \dirname($tmpLogs . '/logs') . ':/mnt/logs:rw';
                $volumes[] = \dirname($tmpLogging . '/logging') . ':/tmp/logging:rw';
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
                if ($version === 'v2') {
                    // TODO: Remove this, release v2 images with script installed
                    $commands = [
                        'sh',
                        '-c',
                        'touch /var/tmp/logs.txt && (' . $command . ') >> /var/tmp/logs.txt 2>&1 && cat /var/tmp/logs.txt'
                    ];
                } else {
                    $commands = [
                        'sh',
                        '-c',
                        'mkdir -p /tmp/logging && touch /tmp/logging/timings.txt && touch /tmp/logging/logs.txt && script --log-out /tmp/logging/logs.txt --flush --log-timing /tmp/logging/timings.txt --return --quiet --command "' . \str_replace('"', '\"', $command) . '"'
                    ];
                }

                try {
                    $statusOutput = '';
                    $status = $orchestration->execute(
                        name: $runtimeName,
                        command: $commands,
                        output: $statusOutput,
                        timeout: $timeout
                    );

                    if (!$status) {
                        throw new Exception('Failed to create runtime: ' . $statusOutput, 400);
                    }

                    if ($version === 'v2') {
                        $output[] = [
                            'timestamp' => Logs::getTimestamp(),
                            'content' => $statusOutput
                        ];
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

            $endTime = \microtime(true);
            $duration = $endTime - $startTime;

            if ($version !== 'v2') {
                $output = Logs::get($runtimeName);
            }

            $container = array_merge($container, [
                'output' => $output,
                'startTime' => $startTime,
                'duration' => $duration,
            ]);

            $activeRuntime = $activeRuntimes->get($runtimeName);
            $activeRuntime['updated'] = \microtime(true);
            $activeRuntime['status'] = 'Up ' . \round($duration, 2) . 's';
            $activeRuntime['initialised'] = 1;
            $activeRuntimes->set($runtimeName, $activeRuntime);
        } catch (Throwable $th) {
            if ($version === 'v2') {
                $message = !empty($output) ? $output : $th->getMessage();
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

                $output = [
                    'timestamp' => Logs::getTimestamp(),
                    'content' => $message
                ];
            } else {
                $output = Logs::get($runtimeName);
                $output = \count($output) > 0 ? $output : [
                    'timestamp' => Logs::getTimestamp(),
                    'content' => $th->getMessage()
                ];
            }

            $localDevice->deletePath($tmpFolder);

            // Silently try to kill container
            try {
                $orchestration->remove($runtimeName, true);
            } catch (Throwable $th) {
            }

            $activeRuntimes->del($runtimeName);

            $message = '';
            foreach ($output as $chunk) {
                $message .= $chunk['content'];
            }

            throw new Exception($message, $th->getCode() ?: 500);
        }

        // Container cleanup
        if ($remove) {
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
    ->param('entrypoint', '', new Text(256, 0), 'Entrypoint of the code file.', true)
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

            $log->addTag('image', $image);
            $log->addTag('version', $version);
            $log->addTag('runtimeId', $runtimeName);

            $variables = \array_merge($variables, [
                'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
            ]);

            $prepareStart = \microtime(true);


            // Prepare runtime
            if (!$activeRuntimes->exists($runtimeName)) {
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
            $runtime = $activeRuntimes->get($runtimeName) ?? [];

            $log->addTag('image', $runtime['image']);

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

                    $lockStart = \microtime(true);
                    while (true) {
                        // If timeout is passed, stop and return error
                        if (\microtime(true) - $lockStart >= $timeout) {
                            break;
                        }

                        if (!$logDevice->exists($logFile . '.lock') && !$logDevice->exists($errorFile . '.lock')) {
                            break;
                        }

                        // Wait 0.1s and check again
                        \usleep(100000);
                    }

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
                $log->addExtra('activeRuntime', $activeRuntimes->get($runtimeName));
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
            'runtimes' => [],
            'usage' => $statsHost->get('host', 'usage') ?? null
        ];

        foreach ($statsContainers as $hostname => $stat) {
            $output['runtimes'][$hostname] = [
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
        try {
            logError($log, $error, "httpError", $logger, $route);
        } catch (Throwable) {
            Console::warning('Unable to send log message');
        }

        $version = System::getEnv('OPR_EXECUTOR_VERSION', 'UNKNOWN');
        $message = $error->getMessage();
        $file = $error->getFile();
        $line = $error->getLine();
        $trace = $error->getTrace();

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

        $output = Http::isDevelopment() ? [
            'message' => $message,
            'code' => $code,
            'file' => $file,
            'line' => $line,
            'trace' => \json_encode($trace, JSON_UNESCAPED_UNICODE) === false ? [] : $trace, // check for failing encode
            'version' => $version
        ] : [
            'message' => $message,
            'code' => $code,
            'version' => $version
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

    $networks = explode(',', Http::getEnv('OPR_EXECUTOR_NETWORK') ?: 'openruntimes-runtimes');

    /*
     * Remove residual runtimes and networks
     */
    Console::info('Removing orphan runtimes and networks...');
    cleanUp($orchestration, $activeRuntimes);
    Console::success("Orphan runtimes and networks removal finished.");

    /**
     * Create and store Docker Bridge networks used for communication between executor and runtimes
     */
    Console::info('Creating networks...');
    $createdNetworks = createNetworks($orchestration, $networks);
    Http::setResource('networks', fn () => $createdNetworks);

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

    $payloadSize = 22 * (1024 * 1024);

    $settings = [
        'package_max_length' => $payloadSize,
        'buffer_output_size' => $payloadSize,
    ];

    $server = new Server('0.0.0.0', '80', $settings);
    $http = new Http($server, 'UTC');

    Console::success('Executor is ready.');

    Process::signal(SIGINT, fn () => cleanUp($orchestration, $activeRuntimes, $networks));
    Process::signal(SIGQUIT, fn () => cleanUp($orchestration, $activeRuntimes, $networks));
    Process::signal(SIGKILL, fn () => cleanUp($orchestration, $activeRuntimes, $networks));
    Process::signal(SIGTERM, fn () => cleanUp($orchestration, $activeRuntimes, $networks));

    $http->start();
});
