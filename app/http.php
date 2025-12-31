<?php

use Utopia\DI\Dependency;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

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
use Utopia\Orchestration\Orchestration;
use Utopia\System\System;

// Unlimited memory limit to handle as many coroutines/requests as possible
ini_set('memory_limit', '-1');

global $container;

$payloadSize = 22 * (1024 * 1024);
$settings = [
    'package_max_length' => $payloadSize,
    'buffer_output_size' => $payloadSize,
    'worker_num' => (int)System::getEnv('OPR_EXECUTOR_WORKER_NUM', '1'),
    'task_worker_num' => 0,
    'max_coroutine' => (int)System::getEnv('OPR_EXECUTOR_MAX_COROUTINES', '100000'),
];

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

Http::setMode((string)System::getEnv('OPR_EXECUTOR_ENV', Http::MODE_TYPE_PRODUCTION));

Http::init()
    ->inject('response')
    ->action(function (Response $response) {
        $response->addHeader('Server', 'Executor');
    });

Http::onStart()
    ->inject('orchestration')
    ->inject('network')
    ->inject('imagePuller')
    ->inject('maintenance')
    ->action(function (
        Orchestration $orchestration,
        Network $network,
        ImagePuller $imagePuller,
        Maintenance $maintenance
    ) use ($container) {
        $hostname = gethostname() ?: throw new \RuntimeException('Could not determine hostname');
        $self = $orchestration->list(['name' => $hostname])[0] ?? throw new \RuntimeException('Own container not found');

        /* Setup the networks */
        $networks = $network->setup(
            networks: explode(',', System::getEnv('OPR_EXECUTOR_NETWORK') ?: 'openruntimes-runtimes'),
            container: $self->getName()
        );
        $networksDependency = (new Dependency())
            ->setName('networks')
            ->setCallback(fn () => $networks);
        $container->set($networksDependency);

        /* Pull images */
        $imagePuller->pull(explode(',', System::getEnv('OPR_EXECUTOR_IMAGES') ?: ''));

        /* Start maintenance task */
        $maintenance->start(
            (int)System::getEnv('OPR_EXECUTOR_MAINTENANCE_INTERVAL', '3600'),
            (int)System::getEnv('OPR_EXECUTOR_INACTIVE_THRESHOLD', '60')
        );

        Console::success('Executor is ready.');
    });

$server = new Server('0.0.0.0', '80', $settings);
$http = new Http($server, $container, 'UTC');
$http->start();
