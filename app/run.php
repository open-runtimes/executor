<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/http.php';

use OpenRuntimes\Executor\Runner\Docker;
use Utopia\CLI\Console;
use Utopia\Http\Http;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;
use Utopia\Http\Adapter\Swoole\Server;

use function Swoole\Coroutine\run;

run(function () {
    $dockerUser = (string) Http::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', '');
    $dockerPass = (string) Http::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '');
    $orchestration = new Orchestration(new DockerAPI($dockerUser, $dockerPass));
    $networks = explode(',', Http::getEnv('OPR_EXECUTOR_NETWORK') ?: 'openruntimes-runtimes');
    $runner = new Docker($orchestration, $networks);
    Http::setResource('runner', fn () => $runner);

    $payloadSize = 22 * (1024 * 1024);
    $settings = [
        'package_max_length' => $payloadSize,
        'buffer_output_size' => $payloadSize,
    ];

    $server = new Server('0.0.0.0', '80', $settings);
    $http = new Http($server, 'UTC');

    Console::success('Executor is ready.');

    $http->start();
});
