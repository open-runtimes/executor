<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/error.php';
require_once __DIR__ . '/controllers.php';

use OpenRuntimes\Executor\Runner\ImagePuller;
use OpenRuntimes\Executor\Runner\Maintenance;
use OpenRuntimes\Executor\Runner\Network;
use Swoole\Runtime;
use Utopia\Console;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\System\System;
use Utopia\Orchestration\Orchestration;

use function Swoole\Coroutine\run;

$payloadSize = 22 * (1024 * 1024);
$settings = [
    'package_max_length' => $payloadSize,
    'buffer_output_size' => $payloadSize,
];

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

Http::setMode((string)System::getEnv('OPR_EXECUTOR_ENV', Http::MODE_TYPE_PRODUCTION));

Http::onStart()
    ->inject('orchestration')
    ->inject('network')
    ->inject('imagePuller')
    ->inject('maintenance')
    ->action(function (Orchestration $orchestration, Network $network, ImagePuller $imagePuller, Maintenance $maintenance) {
        /* Fetch own container information */
        $hostname = gethostname() ?: throw new \RuntimeException('Could not determine hostname');
        $selfContainer = $orchestration->list(['name' => $hostname])[0] ?? throw new \RuntimeException('Own container not found');

        /* Create desired networks if they don't exist */
        $network->setup(
            explode(',', System::getEnv('OPR_EXECUTOR_NETWORK') ?: 'openruntimes-runtimes'),
            $selfContainer->getName()
        );
        Http::setResource('networks', fn (): array => $network->getAvailable());

        /* Pull images */
        $imagePuller->pull(explode(',', System::getEnv('OPR_EXECUTOR_IMAGES') ?: ''));

        /* Start maintenance task */
        $maintenance->start(
            (int)System::getEnv('OPR_EXECUTOR_MAINTENANCE_INTERVAL', '3600'),
            (int)System::getEnv('OPR_EXECUTOR_INACTIVE_THRESHOLD', '60')
        );

        Console::success('Executor is ready.');
    });

Http::onRequest()
    ->inject('response')
    ->action(function (Response $response) {
        $response->addHeader('Server', 'Executor');
    });

run(function () use ($settings) {
    $server = new Server('0.0.0.0', '80', $settings);
    $http = new Http($server, 'UTC');
    $http->start();
});
