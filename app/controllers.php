<?php

require_once __DIR__ . '/init.php';

use OpenRuntimes\Executor\Exception;
use OpenRuntimes\Executor\BodyMultipart;
use OpenRuntimes\Executor\Runner\Adapter as Runner;
use Utopia\System\System;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Validator\AnyOf;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Orchestration\Adapter\DockerAPI;

Http::get('/v1/runtimes/:runtimeId/logs')
    ->groups(['api', 'runtimes'])
    ->desc("Get live stream of logs of a runtime")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->param('timeout', '600', new Text(16), 'Maximum logs timeout.', true)
    ->inject('response')
    ->inject('runner')
    ->action(function (string $runtimeId, string $timeoutStr, Response $response, Runner $runner): void {
        $timeout = \intval($timeoutStr);

        $response->sendHeader('Content-Type', 'text/event-stream');
        $response->sendHeader('Cache-Control', 'no-cache');

        $runner->getLogs($runtimeId, $timeout, $response);

        $response->end();
    });

Http::post('/v1/runtimes/:runtimeId/commands')
    ->desc('Execute a command inside an existing runtime')
    ->param('runtimeId', '', new Text(64), 'Unique runtime ID.')
    ->param('command', '', new Text(1024), 'Command to execute.')
    ->param('timeout', 600, new Integer(), 'Commands execution time in seconds.', true)
    ->inject('response')
    ->inject('runner')
    ->action(function (string $runtimeId, string $command, int $timeout, Response $response, Runner $runner): void {
        $output = $runner->executeCommand($runtimeId, $command, $timeout);
        $response->setStatusCode(Response::STATUS_CODE_OK)->json([ 'output' => $output ]);
    });

Http::post('/v1/runtimes')
    ->groups(['api'])
    ->desc("Create a new runtime server")
    ->param('runtimeId', '', new Text(64), 'Unique runtime ID.')
    ->param('image', '', new Text(128), 'Base image name of the runtime.')
    ->param('entrypoint', '', new Text(256, 0), 'Entrypoint of the code file.', true)
    ->param('source', '', new Text(0, 0), 'Path to source files.', true)
    ->param('destination', '', new Text(0, 0), 'Destination folder to store runtime files into.', true)
    ->param('outputDirectory', '', new Text(0, 0), 'Path inside build to use as output. If empty, entire build is used.', true)
    ->param('variables', [], new Assoc(), 'Environment variables passed into runtime.', true)
    ->param('runtimeEntrypoint', '', new Text(1024, 0), 'Commands to run when creating a container. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('command', '', new Text(1024, 0), 'Commands to run after container is created. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('timeout', 600, new Integer(), 'Commands execution time in seconds.', true)
    ->param('remove', false, new Boolean(), 'Remove a runtime after execution.', true)
    ->param('cpus', 1, new FloatValidator(true), 'Container CPU.', true)
    ->param('memory', 512, new Integer(), 'Container RAM memory.', true)
    ->param('version', 'v5', new WhiteList(\explode(',', System::getEnv('OPR_EXECUTOR_RUNTIME_VERSIONS', 'v5') ?? 'v5')), 'Runtime Open Runtime version.', true)
    ->param('restartPolicy', DockerAPI::RESTART_NO, new WhiteList([DockerAPI::RESTART_NO, DockerAPI::RESTART_ALWAYS, DockerAPI::RESTART_ON_FAILURE, DockerAPI::RESTART_UNLESS_STOPPED], true), 'Define restart policy for the runtime once an exit code is returned. Default value is "no". Possible values are "no", "always", "on-failure", "unless-stopped".', true)
    ->inject('response')
    ->inject('runner')
    ->action(function (string $runtimeId, string $image, string $entrypoint, string $source, string $destination, string $outputDirectory, array $variables, string $runtimeEntrypoint, string $command, int $timeout, bool $remove, float $cpus, int $memory, string $version, string $restartPolicy, Response $response, Runner $runner): void {
        $secret = \bin2hex(\random_bytes(16));

        /**
         * Create container
         */
        $variables = \array_merge($variables, match ($version) {
            'v2' => [
                'INTERNAL_RUNTIME_KEY' => $secret,
                'INTERNAL_RUNTIME_ENTRYPOINT' => $entrypoint,
                'INERNAL_EXECUTOR_HOSTNAME' => System::getHostname()
            ],
            'v4', 'v5' => [
                'OPEN_RUNTIMES_SECRET' => $secret,
                'OPEN_RUNTIMES_ENTRYPOINT' => $entrypoint,
                'OPEN_RUNTIMES_HOSTNAME' => System::getHostname(),
                'OPEN_RUNTIMES_CPUS' => $cpus,
                'OPEN_RUNTIMES_MEMORY' => $memory,
            ],
            default => [],
        });

        if ($outputDirectory !== '' && $outputDirectory !== '0') {
            $variables = \array_merge($variables, [
                'OPEN_RUNTIMES_OUTPUT_DIRECTORY' => $outputDirectory
            ]);
        }

        $variables = \array_merge($variables, [
            'CI' => 'true'
        ]);

        $variables = array_map(strval(...), $variables);

        $container = $runner->createRuntime($runtimeId, $secret, $image, $entrypoint, $source, $destination, $variables, $runtimeEntrypoint, $command, $timeout, $remove, $cpus, $memory, $version, $restartPolicy);
        $response->setStatusCode(Response::STATUS_CODE_CREATED)->json($container);
    });

Http::get('/v1/runtimes')
    ->groups(['api'])
    ->desc("List currently active runtimes")
    ->inject('runner')
    ->inject('response')
    ->action(function (Runner $runner, Response $response): void {
        $response->setStatusCode(Response::STATUS_CODE_OK)->json($runner->getRuntimes());
    });

Http::get('/v1/runtimes/:runtimeId')
    ->groups(['api', 'runtimes'])
    ->desc("Get a runtime by its ID")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->inject('runner')
    ->inject('response')
    ->action(function (string $runtimeId, Runner $runner, Response $response): void {
        $runtimeName = System::getHostname() . '-' . $runtimeId;
        $response->setStatusCode(Response::STATUS_CODE_OK)->json($runner->getRuntime($runtimeName));
    });

Http::delete('/v1/runtimes/:runtimeId')
    ->groups(['api', 'runtimes'])
    ->desc('Delete a runtime')
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->inject('response')
    ->inject('runner')
    ->action(function (string $runtimeId, Response $response, Runner $runner): void {
        $runner->deleteRuntime($runtimeId);
        $response->setStatusCode(Response::STATUS_CODE_OK)->send();
    });

Http::post('/v1/runtimes/:runtimeId/executions')
    ->groups(['api', 'runtimes'])
    ->alias('/v1/runtimes/:runtimeId/execution')
    ->desc('Create an execution')
    // Execution-related
    ->param('runtimeId', '', new Text(64), 'The runtimeID to execute.')
    ->param('body', '', new Text(20971520), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('path', '/', new Text(2048), 'Path from which execution comes.', true)
    ->param('method', 'GET', new Whitelist(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true), 'Path from which execution comes.', true)
    ->param('headers', [], new AnyOf([new Text(65535), new Assoc()], AnyOf::TYPE_MIXED), 'Headers passed into runtime.', true)
    ->param('timeout', 15, new Integer(true), 'Function maximum execution time in seconds.', true)
    // Runtime-related
    ->param('image', '', new Text(128), 'Base image name of the runtime.', true)
    ->param('source', '', new Text(0), 'Path to source files.', true)
    ->param('entrypoint', '', new Text(256, 0), 'Entrypoint of the code file.', true)
    ->param('variables', [], new AnyOf([new Text(65535), new Assoc()], AnyOf::TYPE_MIXED), 'Environment variables passed into runtime.', true)
    ->param('cpus', 1, new FloatValidator(true), 'Container CPU.', true)
    ->param('memory', 512, new Integer(true), 'Container RAM memory.', true)
    ->param('version', 'v5', new WhiteList(\explode(',', System::getEnv('OPR_EXECUTOR_RUNTIME_VERSIONS', 'v5') ?? 'v5')), 'Runtime Open Runtime version.', true)
    ->param('runtimeEntrypoint', '', new Text(1024, 0), 'Commands to run when creating a container. Maximum of 100 commands are allowed, each 1024 characters long.', true)
    ->param('logging', true, new Boolean(true), 'Whether executions will be logged.', true)
    ->param('restartPolicy', DockerAPI::RESTART_NO, new WhiteList([DockerAPI::RESTART_NO, DockerAPI::RESTART_ALWAYS, DockerAPI::RESTART_ON_FAILURE, DockerAPI::RESTART_UNLESS_STOPPED], true), 'Define restart policy once exit code is returned by command. Default value is "no". Possible values are "no", "always", "on-failure", "unless-stopped".', true)
    ->inject('response')
    ->inject('request')
    ->inject('runner')
    ->action(
        function (
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
            Response $response,
            Request $request,
            Runner $runner
        ): void {
            // Parse JSON strings for assoc params when coming from multipart
            if (\is_string($headers)) {
                $headers = \json_decode($headers, true) ?? [];
            }

            if (\is_string($variables)) {
                $variables = \json_decode($variables, true) ?? [];
            }

            /** @var array<string, mixed> $headers */
            /** @var array<string, mixed> $variables */

            // 'headers' validator
            $validator = new Assoc();
            if (!$validator->isValid($headers)) {
                throw new Exception(Exception::EXECUTION_BAD_REQUEST, $validator->getDescription());
            }

            // 'variables' validator
            $validator = new Assoc();
            if (!$validator->isValid($variables)) {
                throw new Exception(Exception::EXECUTION_BAD_REQUEST, $validator->getDescription());
            }

            if (in_array($payload, [null, '', '0'], true)) {
                $payload = '';
            }

            $variables = array_map(strval(...), $variables);

            $execution = $runner->createExecution(
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
            );

            // Backwards compatibility for headers
            $responseFormat = $request->getHeader('x-executor-response-format', '0.10.0'); // Last version without support for array value for headers
            if (version_compare($responseFormat, '0.11.0', '<')) {
                foreach ($execution['headers'] as $key => $value) {
                    if (\is_array($value)) {
                        $execution['headers'][$key] = $value[\array_key_last($value)] ?? '';
                    }
                }
            }

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
                    throw new Exception(Exception::EXECUTION_BAD_JSON);
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
    ->desc("Get health status")
    ->inject('response')
    ->action(function (Response $response): void {
        $response->setStatusCode(Response::STATUS_CODE_OK)->text("OK");
    });

Http::init()
    ->groups(['api'])
    ->inject('request')
    ->action(function (Request $request): void {
        $secretKey = \explode(' ', $request->getHeader('authorization', ''))[1] ?? '';
        if ($secretKey === '' || $secretKey === '0' || $secretKey !== System::getEnv('OPR_EXECUTOR_SECRET', '')) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED, 'Missing executor key');
        }
    });
