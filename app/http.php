<?php

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/error.php';
require_once __DIR__ . '/controllers.php';

use OpenRuntimes\Executor\Runner\Docker;
use OpenRuntimes\Executor\Runner\ImagePuller;
use OpenRuntimes\Executor\Runner\Maintenance;
use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use OpenRuntimes\Executor\Runner\Network;
use Swoole\Process;
use Swoole\Runtime;
use Utopia\Console;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\System\System;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;

use function Swoole\Coroutine\run;

// Unlimited memory limit to handle as many coroutines/requests as possible
ini_set('memory_limit', '-1');

$payloadSize = 22 * (1024 * 1024);
$settings = [
    'package_max_length' => $payloadSize,
    'buffer_output_size' => $payloadSize,
];

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

Http::setMode((string)System::getEnv('OPR_EXECUTOR_ENV', Http::MODE_TYPE_PRODUCTION));

Http::onRequest()
    ->inject('response')
    ->action(function (Response $response) {
        $response->addHeader('Server', 'Executor');
    });

run(function () use ($settings) {
    $orchestration = new Orchestration(new DockerAPI(
        System::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', ''),
        System::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '')
    ));
    $runtimes = new Runtimes();

    /* Fetch own container information */
    $hostname = gethostname() ?: throw new \RuntimeException('Could not determine hostname');
    $selfContainer = $orchestration->list(['name' => $hostname])[0] ?? throw new \RuntimeException('Own container not found');

    /* Create desired networks if they don't exist */
    $network = new Network($orchestration);
    $network->setup(
        explode(',', System::getEnv('OPR_EXECUTOR_NETWORK') ?: 'openruntimes-runtimes'),
        $selfContainer->getName()
    );

    /* Pull images */
    $imagePuller = new ImagePuller($orchestration);
    $imagePuller->pull(explode(',', System::getEnv('OPR_EXECUTOR_IMAGES') ?: ''));

    /* Start maintenance task */
    $maintenance = new Maintenance($orchestration, $runtimes);
    $maintenance->start(
        (int)System::getEnv('OPR_EXECUTOR_MAINTENANCE_INTERVAL', '3600'),
        (int)System::getEnv('OPR_EXECUTOR_INACTIVE_THRESHOLD', '60')
    );

    /* Runner service, used to manage runtimes */
    $runner = new Docker($orchestration, $runtimes, $network->getAvailable());
    Http::setResource('runner', fn () => $runner);

    $server = new Server('0.0.0.0', '80', $settings);
    $http = new Http($server, 'UTC');

    Process::signal(SIGTERM, function () use ($maintenance, $runner, $network) {
        // This doesn't actually work. We need to fix utopia-php/http@0.34.x
        $maintenance->stop();
        $network->cleanup();
        $runner->cleanup();
    });

    Console::success('Executor is ready.');

    $http->start();
});
