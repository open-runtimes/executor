<?php

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/error.php';
require_once __DIR__ . '/controllers.php';

use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\System\System;

/** @var Utopia\DI\Container $container */

ini_set('memory_limit', '-1');

Http::setMode((string) System::getEnv('OPR_EXECUTOR_ENV', Http::MODE_TYPE_PRODUCTION));

Http::init()
    ->inject('response')
    ->action(function (Response $response) {
        $response->addHeader('Server', 'Executor');
    });

$payloadSize = 22 * (1024 * 1024);

$server = new Server('0.0.0.0', '80', [
    'open_http2_protocol' => true,
    'package_max_length' => $payloadSize,
    'buffer_output_size' => $payloadSize,
]);

$http = new Http($server, $container, 'UTC');
$http->start();
