<?php

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/controllers.php';

use Swoole\Runtime;
use Utopia\CLI\Console;
use Utopia\Http\Http;
use Utopia\Http\Adapter\Swoole\Server;

use function Swoole\Coroutine\run;

// Unlimited memory limit to handle as many coroutines/requests as possible
ini_set('memory_limit', '-1');

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

Http::setMode((string)Http::getEnv('OPR_EXECUTOR_ENV', Http::MODE_TYPE_PRODUCTION));

run(function () {
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
