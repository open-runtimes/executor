<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Runtimes\Runtimes;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Coroutine\Http\Server;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Storage;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Pools\Pool;
use Utopia\Registry\Registry;

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

App::setMode((string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_ENV', App::MODE_TYPE_PRODUCTION));

// Setup Registry
$register = new Registry();

/**
* Create logger for cloud logging
*/
$register->set('logger', function () {
    $providerName = App::getEnv('OPEN_RUNTIMES_EXECUTOR_LOGGING_PROVIDER', '');
    $providerConfig = App::getEnv('OPEN_RUNTIMES_EXECUTOR_LOGGING_CONFIG', '');
    $logger = null;

    if (!empty($providerName) && !empty($providerConfig) && Logger::hasProvider($providerName)) {
        $adapter = match ($providerName) {
            'sentry' => new Sentry($providerConfig),
            'raygun' => new Raygun($providerConfig),
            'logowl' => new LogOwl($providerConfig),
            'appsignal' => new AppSignal($providerConfig),
            default => throw new Exception('Provider "' . $providerName . '" not supported.')
        };

        $logger = new Logger($adapter);
    }

    return $logger;
});

/**
 * Create orchestration pool
 */
$register->set('orchestrationPool', function () {
    $pool = new Pool('orchestration-pool', 30, function () {
        $dockerUser = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_DOCKER_HUB_USERNAME', '');
        $dockerPass = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_DOCKER_HUB_PASSWORD', '');
        $orchestration = new Orchestration(new DockerCLI($dockerUser, $dockerPass));
        return $orchestration;
    });

    $pool->setReconnectAttempts(3);
    $pool->setReconnectSleep(5);

    return $pool;
});

/**
* Create a Swoole table to store runtime information
*/
$register->set('activeRuntimes', function () {
    $table = new Table(1024);

    $table->column('id', Table::TYPE_STRING, 256);
    $table->column('created', Table::TYPE_INT, 8);
    $table->column('updated', Table::TYPE_INT, 8);
    $table->column('name', Table::TYPE_STRING, 256);
    $table->column('hostname', Table::TYPE_STRING, 256);
    $table->column('status', Table::TYPE_STRING, 128);
    $table->column('key', Table::TYPE_STRING, 256);
    $table->create();

    return $table;
});

/** Set Resources */
App::setResource('register', function () use (&$register) {
    return $register;
});

App::setResource('orchestrationPool', function (Registry $register) {
    return $register->get('orchestrationPool');
}, ['register']);

App::setResource('activeRuntimes', function (Registry $register) {
    return $register->get('activeRuntimes');
}, ['register']);

App::setResource('logger', function (Registry $register) {
    return $register->get('logger');
}, ['register']);

function logError(Throwable $error, string $action, Logger $logger = null, Utopia\Route $route = null): void
{
    if ($logger) {
        $version = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_VERSION', 'UNKNOWN');

        $log = new Log();
        $log->setNamespace("executor");
        $log->setServer(\gethostname() !== false ? \gethostname() : null);
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        if ($route) {
            $log->addTag('method', $route->getMethod());
            $log->addTag('url', $route->getPath());
        }

        $log->addTag('code', $error->getCode());
        $log->addTag('verboseType', get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());
        $log->addExtra('detailedTrace', $error->getTrace());

        $log->setAction($action);

        $log->setEnvironment(App::isProduction() ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Executor log pushed with status code: ' . $responseCode);
    }

    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());
}

function getStorageDevice(string $root): Device
{
    switch (App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_DEVICE', Storage::DEVICE_LOCAL)) {
        case Storage::DEVICE_LOCAL:
        default:
            return new Local($root);
        case Storage::DEVICE_S3:
            $s3AccessKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_S3_ACCESS_KEY', '');
            $s3SecretKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_S3_SECRET', '');
            $s3Region = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_S3_REGION', '');
            $s3Bucket = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_S3_BUCKET', '');
            $s3Acl = 'private';
            return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
        case Storage::DEVICE_DO_SPACES:
            $doSpacesAccessKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_DO_SPACES_ACCESS_KEY', '');
            $doSpacesSecretKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_DO_SPACES_SECRET', '');
            $doSpacesRegion = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_DO_SPACES_REGION', '');
            $doSpacesBucket = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_DO_SPACES_BUCKET', '');
            $doSpacesAcl = 'private';
            return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
        case Storage::DEVICE_BACKBLAZE:
            $backblazeAccessKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_BACKBLAZE_ACCESS_KEY', '');
            $backblazeSecretKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_BACKBLAZE_SECRET', '');
            $backblazeRegion = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_BACKBLAZE_REGION', '');
            $backblazeBucket = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_BACKBLAZE_BUCKET', '');
            $backblazeAcl = 'private';
            return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
        case Storage::DEVICE_LINODE:
            $linodeAccessKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_LINODE_ACCESS_KEY', '');
            $linodeSecretKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_LINODE_SECRET', '');
            $linodeRegion = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_LINODE_REGION', '');
            $linodeBucket = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_LINODE_BUCKET', '');
            $linodeAcl = 'private';
            return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
        case Storage::DEVICE_WASABI:
            $wasabiAccessKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_WASABI_ACCESS_KEY', '');
            $wasabiSecretKey = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_WASABI_SECRET', '');
            $wasabiRegion = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_WASABI_REGION', '');
            $wasabiBucket = (string) App::getEnv('OPEN_RUNTIMES_EXECUTOR_STORAGE_WASABI_BUCKET', '');
            $wasabiAcl = 'private';
            return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
    }
}

function removeAllRuntimes(Pool $orchestrationPool): void
{
    Console::log('Cleaning up containers before shutdown...');

    $connection = $orchestrationPool->pop();
    $orchestration = $connection->getResource();
    $functionsToRemove = $orchestration->list(['label' => 'openruntimes-executor=' . System::getHostname()]);
    $orchestrationPool->push($connection);

    if (\count($functionsToRemove) === 0) {
        Console::info('No containers found to clean up.');
    }

    $callables = [];

    foreach ($functionsToRemove as $container) {
        $callables[] = function () use ($container, $orchestrationPool) {
            try {
                $connection = $orchestrationPool->pop();
                $orchestration = $connection->getResource();
                $orchestration->remove($container->getId(), true);
                Console::success('Removed container ' . $container->getName());
            } catch (\Throwable $th) {
                Console::error('Failed to remove container: ' . $container->getName());
                Console::error($th);
            } finally {
                isset($connection) && $orchestrationPool->push($connection);
            }
        };
    }

    /** @phpstan-ignore-next-line */
    Swoole\Coroutine\batch(
        $callables
    );

    Console::success('Done.');
}

App::post('/v1/runtimes')
    ->desc("Create a new runtime server")
    ->param('runtimeId', '', new Text(64), 'Unique runtime ID.')
    ->param('image', '', new Text(128), 'Base image name of the runtime.')
    ->param('source', '', new Text(0), 'Path to source files.')
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file.')
    ->param('destination', '', new Text(0), 'Destination folder to store build files into.', true)
    ->param('variables', [], new Assoc(), 'Environment Variables required for the build.', true)
    ->param('commands', [], new ArrayList(new Text(1024), 100), 'Commands required to build the container. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('workdir', '', new Text(256), 'Working directory.', true)
    ->param('remove', false, new Boolean(), 'Remove a runtime after execution.', true)
    ->inject('orchestrationPool')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, string $image, string $source, string $entrypoint, string $destination, array $variables, array $commands, string $workdir, bool $remove, Pool $orchestrationPool, Table $activeRuntimes, Response $response) {
        $activeRuntimeId = $runtimeId; // Used with Swoole table (key)
        $runtimeId = System::getHostname() . '-' . $runtimeId; // Used in Docker (name)

        $runtimeHostname = \uniqid();

        if ($activeRuntimes->exists($activeRuntimeId)) {
            if ($activeRuntimes->get($activeRuntimeId)['status'] == 'pending') {
                throw new \Exception('A runtime with the same ID is already being created. Attempt a execution soon.', 500);
            }

            throw new Exception('Runtime already exists.', 409);
        }

        $container = [];
        $containerId = '';
        $stdout = '';
        $stderr = '';
        $startTimeUnix = \microtime(true);
        $endTimeUnix = 0;
        $connection = $orchestrationPool->pop();
        $orchestration = $connection->getResource();

        $secret = \bin2hex(\random_bytes(16));

        if (!$remove) {
            $activeRuntimes->set($activeRuntimeId, [
                'id' => $containerId,
                'name' => $activeRuntimeId,
                'hostname' => $runtimeHostname,
                'created' => (int) $startTimeUnix,
                'updated' => \time(),
                'status' => 'pending',
                'key' => $secret,
            ]);
        }

        try {
            Console::info('Building container : ' . $runtimeId);

            /**
             * Temporary file paths in the executor
             */
            $tmpSource = "/tmp/$runtimeId/src/code.tar.gz";
            $tmpBuild = "/tmp/$runtimeId/builds/code.tar.gz";

            /**
             * Copy code files from source to a temporary location on the executor
             */
            $sourceDevice = getStorageDevice("/");
            $localDevice = new Local();
            $buffer = $sourceDevice->read($source);
            if (!$localDevice->write($tmpSource, $buffer)) {
                throw new Exception('Failed to copy source code to temporary directory', 500);
            };

            /**
             * Create the mount folder
             */
            if (!\file_exists(\dirname($tmpBuild))) {
                if (!@\mkdir(\dirname($tmpBuild), 0755, true)) {
                    throw new Exception("Failed to create temporary directory", 500);
                }
            }

            /**
             * Create container
             */
            $variables = \array_merge($variables, [
                'INTERNAL_RUNTIME_KEY' => $secret,
                'INTERNAL_RUNTIME_ENTRYPOINT' => $entrypoint,
                'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
            ]);
            $variables = array_map(fn ($v) => strval($v), $variables);
            $orchestration
                ->setCpus((int) App::getEnv('OPEN_RUNTIMES_EXECUTOR_CPUS', '0'))
                ->setMemory((int) App::getEnv('OPEN_RUNTIMES_EXECUTOR_MEMORY', '0'))
                ->setSwap((int) App::getEnv('OPEN_RUNTIMES_EXECUTOR_MEMORY_SWAP', '0'));

            /** Keep the container alive if we have commands to be executed */
            $entrypoint = !empty($commands) ? [
                'tail',
                '-f',
                '/dev/null'
            ] : [];

            $containerId = $orchestration->run(
                image: $image,
                name: $runtimeId,
                hostname: $runtimeHostname,
                vars: $variables,
                command: $entrypoint,
                labels: [
                    'openruntimes-executor' => System::getHostname()
                ],
                workdir: $workdir,
                volumes: [
                    \dirname($tmpSource) . ':/tmp:rw',
                    \dirname($tmpBuild) . ':/usr/code:rw'
                ]
            );

            if (empty($containerId)) {
                throw new Exception('Failed to create build container', 500);
            }

            $orchestration->networkConnect($runtimeId, App::getEnv('OPEN_RUNTIMES_EXECUTOR_NETWORK', 'executor_runtimes'));

            /**
             * Execute any commands if they were provided
             */
            if (!empty($commands)) {
                $status = $orchestration->execute(
                    name: $runtimeId,
                    command: $commands,
                    stdout: $stdout,
                    stderr: $stderr,
                    timeout: App::getEnv('OPEN_RUNTIMES_EXECUTOR_BUILD_TIMEOUT', 900)
                );

                if (!$status) {
                    throw new Exception('Failed to build dependenices ' . $stderr, 500);
                }
            }

            /**
             * Move built code to expected build directory
             */
            if (!empty($destination)) {
                // Check if the build was successful by checking if file exists
                if (!\file_exists($tmpBuild)) {
                    throw new Exception('Something went wrong during the build process', 500);
                }

                $destinationDevice = getStorageDevice($destination);
                $outputPath = $destinationDevice->getPath(\uniqid() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));

                $buffer = $localDevice->read($tmpBuild);
                if (!$destinationDevice->write($outputPath, $buffer, $localDevice->getFileMimeType($tmpBuild))) {
                    throw new Exception('Failed to move built code to storage', 500);
                };

                $container['outputPath'] = $outputPath;
            }

            /** @phpstan-ignore-next-line */
            if (empty($stdout)) {
                $stdout = 'Build Successful!';
            }

            $endTimeUnix = \microtime(true);
            $duration = $endTimeUnix - $startTimeUnix;

            $container = array_merge($container, [
                'status' => 'ready',
                'stdout' => \mb_strcut($stdout, 0, 1000000), // Limit to 1MB
                'stderr' => \mb_strcut($stderr, 0, 1000000), // Limit to 1MB
                'startTimeUnix' => (int) $startTimeUnix,
                'endTimeUnix' => (int) $endTimeUnix,
                'duration' => $duration,
            ]);

            if (!$remove) {
                $activeRuntimes->set($activeRuntimeId, [
                    'id' => $containerId,
                    'name' => $activeRuntimeId,
                    'hostname' => $runtimeHostname,
                    'created' => (int) $startTimeUnix,
                    'updated' => \time(),
                    'status' => 'Up ' . \round($duration, 2) . 's',
                    'key' => $secret,
                ]);
            }

            Console::success('Build Stage completed in ' . ($duration) . ' seconds');
        } catch (Throwable $th) {
            Console::error('Build failed: ' . $th->getMessage() . $stdout);

            $activeRuntimes->del($activeRuntimeId);
            // Silently try to kill container
            try {
                $orchestration->remove($containerId, true);
            } catch(Throwable $th) {
            }

            $orchestrationPool->push($connection);

            throw new Exception($th->getMessage() . $stdout, 500);
        }

        // Container cleanup
        if ($remove) {
            $activeRuntimes->del($activeRuntimeId);
            try {
                // Try to remove with contaier name instead of ID
                $orchestration->remove($runtimeId, true);
            } catch (Throwable $th) {
                // If fails, means initialization also failed.
                // Container is not there, no need to remove
            }
        }

        // Release connection back to pool, we are done with it
        $orchestrationPool->push($connection);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($container);
    });


App::get('/v1/runtimes')
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

App::get('/v1/runtimes/:runtimeId')
    ->desc("Get a runtime by its ID")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, Table $activeRuntimes, Response $response) {
        $activeRuntimeId = $runtimeId; // Used with Swoole table (key)

        if (!$activeRuntimes->exists($activeRuntimeId)) {
            throw new Exception('Runtime not found', 404);
        }

        $runtime = $activeRuntimes->get($activeRuntimeId);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($runtime);
    });

App::delete('/v1/runtimes/:runtimeId')
    ->desc('Delete a runtime')
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.', false)
    ->inject('orchestrationPool')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, Pool $orchestrationPool, Table $activeRuntimes, Response $response) {
        $activeRuntimeId = $runtimeId; // Used with Swoole table (key)
        $runtimeId = System::getHostname() . '-' . $runtimeId; // Used in Docker (name)

        if (!$activeRuntimes->exists($activeRuntimeId)) {
            throw new Exception('Runtime not found', 404);
        }

        Console::info('Deleting runtime: ' . $runtimeId);

        try {
            $connection = $orchestrationPool->pop();
            $orchestration = $connection->getResource();
            $orchestration->remove($runtimeId, true);
            $activeRuntimes->del($activeRuntimeId);
            Console::success('Removed runtime container: ' . $runtimeId);
        } finally {
            isset($connection) && $orchestrationPool->push($connection);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });

App::post('/v1/execution')
    ->desc('Create an execution')
    // Execution-related
    ->param('runtimeId', '', new Text(64), 'The runtimeID to execute.')
    ->param('payload', '', new Text(8192), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('variables', [], new Assoc(), 'Environment variables required for the build and execution.', true)
    ->param('timeout', 15, new Range(1, (int) App::getEnv('OPEN_RUNTIMES_EXECUTOR_MAX_TIMEOUT', "900")), 'Function maximum execution time in seconds.', true)
    // Runtime-related
    ->param('image', '', new Text(128), 'Base image name of the runtime.', true)
    ->param('source', '', new Text(0), 'Path to source files.', true)
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file.', true)
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(
        function (string $runtimeId, string $payload, array $variables, int $timeout, string $image, string $source, string $entrypoint, Table $activeRuntimes, Response $response) {
            $activeRuntimeId = $runtimeId; // Used with Swoole table (key)
            $runtimeId = System::getHostname() . '-' . $runtimeId; // Used in Docker (name)

            $variables = \array_merge($variables, [
                'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
            ]);

            // Prepare runtime
            if (!$activeRuntimes->exists($activeRuntimeId)) {
                if (empty($image) || empty($source) || empty($entrypoint)) {
                    throw new Exception('Runtime not found. Please start it first or provide runtime-related parameters.', 401);
                }

                // Prepare request to executor
                $sendCreateRuntimeRequest = function () use ($activeRuntimeId, $image, $source, $entrypoint, $variables) {
                    $statusCode = 0;
                    $errNo = -1;
                    $executorResponse = '';

                    $ch = \curl_init();

                    $body = \json_encode([
                        'runtimeId' => $activeRuntimeId,
                        'image' => $image,
                        'source' => $source,
                        'entrypoint' => $entrypoint,
                        'variables' => $variables
                    ]);

                    \curl_setopt($ch, CURLOPT_URL, "http://localhost/v1/runtimes");
                    \curl_setopt($ch, CURLOPT_POST, true);
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    \curl_setopt($ch, CURLOPT_TIMEOUT, ((int) App::getEnv('OPEN_RUNTIMES_EXECUTOR_BUILD_TIMEOUT', '900')) + 2); // max 2 seconds expected network latency
                    \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

                    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Content-Length: ' . \strlen($body ?: ''),
                        'authorization: Bearer ' . App::getEnv('OPEN_RUNTIMES_EXECUTOR_SECRET', '')
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
                for ($i = 0; $i < 5; $i++) {
                    [ 'errNo' => $errNo, 'error' => $error, 'statusCode' => $statusCode, 'executorResponse' => $executorResponse ] = \call_user_func($sendCreateRuntimeRequest);

                    // No error
                    if ($errNo === 0) {
                        if ($statusCode >= 400) {
                            $body = \json_decode($error, true);
                            throw new Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . ($body['message'] ?? $error), 500);
                        }
                        break;
                    }

                    if ($errNo !== 111) { // Connection Refused - see https://openswoole.com/docs/swoole-error-code
                        throw new Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                    }

                    Console::info('Waiting for runtime to respond...');

                    if ($i === 4) {
                        throw new Exception('An internal curl error has occurred while starting runtime! Error Msg: ' . $error, 500);
                    }

                    \sleep(1);
                }
            }

            // Ensure runtime started
            for ($i = 0; $i < 5; $i++) {
                if ($activeRuntimes->get($activeRuntimeId)['status'] === 'pending') {
                    Console::info('Waiting for runtime to be ready...');
                } else {
                    break;
                }

                if ($i === 4) {
                    throw new Exception('Runtime failed to launch in allocated time.', 500);
                }

                \sleep(1);
            }

            // Ensure we have secret
            $runtime = $activeRuntimes->get($activeRuntimeId);
            $hostname = $runtime['hostname'];
            $secret = $runtime['key'];
            if (empty($secret)) {
                throw new Exception('Runtime secret not found. Please re-create the runtime.', 500);
            }

            Console::info('Executing Runtime: ' . $runtimeId);

            $executionStart = \microtime(true);

            // Prepare request to runtime
            $sendExecuteRequest = function () use ($variables, $payload, $secret, $hostname, &$executionStart, $timeout) {
                // Restart execution timer to not could failed attempts
                $executionStart = \microtime(true);

                $statusCode = 0;
                $errNo = -1;
                $executorResponse = '';

                $ch = \curl_init();

                $body = \json_encode([
                    'variables' => $variables,
                    'payload' => $payload,
                    'headers' => [] // TODO: @Meldiron Forward headers when becomes relevant (Appwrite proxy)
                ]);

                \curl_setopt($ch, CURLOPT_URL, "http://" . $hostname . ":3000/");
                \curl_setopt($ch, CURLOPT_POST, true);
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout + 2); // max 2 seconds expected network latency
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

                return [
                    'errNo' => $errNo,
                    'error' => $error,
                    'statusCode' => $statusCode,
                    'executorResponse' => $executorResponse
                ];
            };

            // Execute function
            for ($i = 0; $i < 5; $i++) {
                [ 'errNo' => $errNo, 'error' => $error, 'statusCode' => $statusCode, 'executorResponse' => $executorResponse ] = \call_user_func($sendExecuteRequest);

                // No error
                if ($errNo === 0) {
                    break;
                }

                if ($errNo !== 111) { // Connection Refused - see https://openswoole.com/docs/swoole-error-code
                    throw new Exception('An internal curl error has occurred within the executor! Error Msg: ' . $error, 500);
                }

                Console::info('Waiting for runtime to respond...');

                if ($i === 4) {
                    throw new Exception('An internal curl error has occurred within the executor! Error Msg: ' . $error, 500);
                }

                \sleep(1);
            }

            // Extract response
            $execution = [];
            $stdout = '';
            $stderr = '';
            $res = '';

            $executorResponse = json_decode($executorResponse ?? '{}', true);

            switch (true) {
                case $statusCode >= 500:
                    $stderr = $executorResponse['stderr'] ?? '';
                    $stdout = $executorResponse['stdout'] ?? '';
                    break;
                case $statusCode >= 100:
                    $stdout = $executorResponse['stdout'] ?? '';
                    $res = $executorResponse['response'] ?? '';
                    if (is_array($res)) {
                        $res = json_encode($res, JSON_UNESCAPED_UNICODE);
                    }
                    break;
                default:
                    $stderr = $executorResponse['stderr'] ?? '';
                    $stdout = $executorResponse['stdout'] ?? '';
                    break;
            }

            $executionEnd = \microtime(true);
            $executionTime = ($executionEnd - $executionStart);
            $functionStatus = ($statusCode >= 500) ? 'failed' : 'completed';

            Console::success('Function executed in ' . $executionTime . ' seconds, status: ' . $functionStatus);

            $execution = [
                'status' => $functionStatus,
                'statusCode' => $statusCode,
                'response' => \mb_strcut($res, 0, 1000000), // Limit to 1MB
                'stdout' => \mb_strcut($stdout, 0, 1000000), // Limit to 1MB
                'stderr' => \mb_strcut($stderr, 0, 1000000), // Limit to 1MB
                'duration' => $executionTime,
            ];

            // Update swoole table
            $runtime = $activeRuntimes->get($activeRuntimeId);
            $runtime['updated'] = \time();
            $activeRuntimes->set($activeRuntimeId, $runtime);

            // Finish request
            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->json($execution);
        }
    );

App::get('/v1/health')
->desc("Get health status of host machine and runtimes.")
->inject('orchestrationPool')
->inject('response')
->action(function (Pool $orchestrationPool, Response $response) {
    // TODO: @Meldiron Interval, here just read from Table.

    $output = [
        'status' => 'pass'
    ];

    /** @phpstan-ignore-next-line */
    Swoole\Coroutine\batch(
        [
            function () use (&$output) {
                $output['hostUsage'] = System::getCPUUsage(5);
            },
            function () use (&$output, $orchestrationPool) {
                $functionsUsage = [];

                try {
                    $connection = $orchestrationPool->pop();
                    $orchestration = $connection->getResource();
                    $containerUsages = $orchestration->getStats(
                        filters: [ 'label' => 'openruntimes-executor=' . System::getHostname() ],
                        cycles: 3
                    );

                    foreach ($containerUsages as $containerUsage) {
                        $functionsUsage[$containerUsage['name']] = $containerUsage['cpu'] * 100;
                    }
                } finally {
                    isset($connection) && $orchestrationPool->push($connection);
                }

                $output['functionsUsage'] = $functionsUsage;
            }
        ]
    );

    $response
        ->setStatusCode(Response::STATUS_CODE_OK)
        ->json($output);
});


/** Set callbacks */
App::error()
    ->inject('utopia')
    ->inject('error')
    ->inject('logger')
    ->inject('request')
    ->inject('response')
    ->action(function (App $utopia, Throwable $error, ?Logger $logger, Request $request, Response $response) {
        $route = $utopia->match($request);
        logError($error, "httpError", $logger, $route);

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
            'version' => App::getEnv('OPEN_RUNTIMES_EXECUTOR_VERSION', 'UNKNOWN')
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($code);

        $response->json($output);
    });

App::init()
    ->inject('request')
    ->action(function (Request $request) {
        $secretKey = \explode(' ', $request->getHeader('authorization', ''))[1] ?? '';
        if (empty($secretKey) || $secretKey !== App::getEnv('OPEN_RUNTIMES_EXECUTOR_SECRET', '')) {
            throw new Exception('Missing executor key', 401);
        }
    });

/** @phpstan-ignore-next-line */
Co\run(
    function () use ($register) {
        $orchestrationPool = $register->get('orchestrationPool');
        $activeRuntimes = $register->get('activeRuntimes');

        /**
         * Remove residual runtimes
         */
        Console::info('Removing orphan runtimes...');
        try {
            $connection = $orchestrationPool->pop();
            $orchestration = $connection->getResource();
            $orphans = $orchestration->list(['label' => 'openruntimes-executor=' . System::getHostname()]);
        } finally {
            isset($connection) && $orchestrationPool->push($connection);
        }

        if (\count($orphans) === 0) {
            Console::log("No orphan runtimes found.");
        }

        $callables = [];
        foreach ($orphans as $runtime) {
            $callables[] = function () use ($runtime, $orchestrationPool) {
                try {
                    $connection = $orchestrationPool->pop();
                    $orchestration = $connection->getResource();
                    $orchestration->remove($runtime->getName(), true);
                    Console::success("Successfully removed {$runtime->getName()}");
                } catch (\Throwable $th) {
                    Console::error('Orphan runtime deletion failed: ' . $th->getMessage());
                } finally {
                    isset($connection) && $orchestrationPool->push($connection);
                }
            };
        }

        /** @phpstan-ignore-next-line */
        Swoole\Coroutine\batch(
            $callables
        );

        Console::success("Done.");

        /**
         * Warmup: make sure images are ready to run fast 🚀
         */
        Console::info('Pulling runtime images...');
        $runtimes = new Runtimes('v2');
        $allowList = empty(App::getEnv('OPEN_RUNTIMES_EXECUTOR_RUNTIMES')) ? [] : \explode(',', App::getEnv('OPEN_RUNTIMES_EXECUTOR_RUNTIMES'));
        $runtimes = $runtimes->getAll(true, $allowList);
        $callables = [];
        foreach ($runtimes as $runtime) {
            $callables[] = function () use ($runtime, $orchestrationPool) {
                try {
                    $connection = $orchestrationPool->pop();
                    $orchestration = $connection->getResource();
                    Console::log('Warming up ' . $runtime['name'] . ' ' . $runtime['version'] . ' environment...');
                    $response = $orchestration->pull($runtime['image']);
                    if ($response) {
                        Console::info("Successfully Warmed up {$runtime['name']} {$runtime['version']}!");
                    } else {
                        Console::warning("Failed to Warmup {$runtime['name']} {$runtime['version']}!");
                    }
                } finally {
                    isset($connection) && $orchestrationPool->push($connection);
                }
            };
        }

        /** @phpstan-ignore-next-line */
        Swoole\Coroutine\batch(
            $callables
        );

        Console::success("Done.");

        /**
         * Run a maintenance worker every X seconds to remove inactive runtimes
         */
        Console::info('Starting maintenance interval...');
        $interval = (int) App::getEnv('OPEN_RUNTIMES_EXECUTOR_MAINTENANCE_INTERVAL', '3600'); // In seconds
        Timer::tick($interval * 1000, function () use ($orchestrationPool, $activeRuntimes) {
            Console::info("Running maintenance task ...");
            foreach ($activeRuntimes as $activeRuntimeId => $runtime) {
                $inactiveThreshold = \time() - App::getEnv('OPEN_RUNTIMES_EXECUTOR_INACTIVE_TRESHOLD', 60);
                if ($runtime['updated'] < $inactiveThreshold) {
                    go(function () use ($activeRuntimeId, $runtime, $orchestrationPool, $activeRuntimes) {
                        try {
                            $connection = $orchestrationPool->pop();
                            $orchestration = $connection->getResource();
                            $orchestration->remove($runtime['name'], true);
                            $activeRuntimes->del($activeRuntimeId);
                            Console::success("Successfully removed {$runtime['name']}");
                        } catch (\Throwable $th) {
                            Console::error('Inactive Runtime deletion failed: ' . $th->getMessage());
                        } finally {
                            isset($connection) && $orchestrationPool->push($connection);
                        }
                    });
                }
            }
            Console::success("Done.");
        });

        Console::success('Done.');

        $server = new Server('0.0.0.0', 80, false);

        $server->handle('/', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
            $request = new Request($swooleRequest);
            $response = new Response($swooleResponse);

            $app = new App('UTC');

            try {
                $app->run($request, $response);
            } catch (\Throwable $th) {
                $code = 500;

                /**
                 * @var Logger $logger
                 */
                $logger = $app->getResource('logger');
                logError($th, "serverError", $logger);
                $swooleResponse->setStatusCode($code);
                $output = [
                    'message' => 'Error: ' . $th->getMessage(),
                    'code' => $code,
                    'file' => $th->getFile(),
                    'line' => $th->getLine(),
                    'trace' => $th->getTrace()
                ];
                $swooleResponse->end(\json_encode($output));
            }
        });

        Console::success('Executor is ready.');

        Swoole\Process::signal(SIGINT, fn () => removeAllRuntimes($orchestrationPool));
        Swoole\Process::signal(SIGQUIT, fn () => removeAllRuntimes($orchestrationPool));
        Swoole\Process::signal(SIGKILL, fn () => removeAllRuntimes($orchestrationPool));
        Swoole\Process::signal(SIGTERM, fn () => removeAllRuntimes($orchestrationPool));

        $server->start();
    }
);
