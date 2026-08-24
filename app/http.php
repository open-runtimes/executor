<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/error.php';
require_once __DIR__ . '/controllers.php';

use OpenRuntimes\Executor\Runner\ImagePuller;
use OpenRuntimes\Executor\Runner\Maintenance;
use OpenRuntimes\Executor\Runner\Network;
use Swoole\Runtime;
use Utopia\Console;
use Utopia\DI\Container;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Adapter\SwooleCoroutine\Server;
use Utopia\System\System;
use Utopia\Orchestration\Orchestration;

use function Swoole\Coroutine\run;

$payloadSize = 22 * (1024 * 1024);
$settings = [
    'package_max_length' => $payloadSize,
    'buffer_output_size' => $payloadSize,
];

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Http::setMode((string)System::getEnv('OPR_EXECUTOR_ENV', Http::MODE_TYPE_PRODUCTION));

Http::onStart()
    ->inject('orchestration')
    ->inject('network')
    ->inject('imagePuller')
    ->inject('maintenance')
    ->action(function (Orchestration $orchestration, Network $network, ImagePuller $imagePuller, Maintenance $maintenance): void {
        /** @var Container $container */
        global $container;

        /* Fetch own container information */
        $hostname = gethostname() ?: throw new \RuntimeException('Could not determine hostname');
        $selfContainer = $orchestration->list(['name' => $hostname])[0] ?? throw new \RuntimeException('Own container not found');

        /* Create desired networks if they don't exist */
        $network->setup(
            explode(',', System::getEnv('OPR_EXECUTOR_NETWORK') ?: 'openruntimes-runtimes'),
            $selfContainer->getName()
        );
        $container->set(
            'networks',
            $network->getAvailable(...)
        );

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
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response): void {
        $response->addHeader('Server', 'Executor');

        // utopia-php/http keeps an empty JSON object as stdClass so `{}` stays
        // distinguishable from `[]`. No param here wants that distinction — the
        // object-shaped ones are string maps, and an empty map is an empty map —
        // while the Assoc validator takes arrays only, so `"variables": {}` would
        // be a 400 that older executors accepted.
        $params = $request->getParams();
        $flattened = array_map(
            fn (mixed $value): mixed => $value instanceof stdClass && (array)$value === [] ? [] : $value,
            $params
        );

        // Only the payload is rewritten, and only when it held such an object; a
        // query string cannot, and writing one back as the payload would move it.
        if ($flattened !== $params) {
            $request->setPayload($flattened);
        }
    });

run(function () use ($settings): void {
    /** @var Container $container */
    global $container;

    $server = new Server('0.0.0.0', '80', $settings, $container);
    $http = new Http($server, 'UTC');
    $http->start();
});
