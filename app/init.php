<?php

use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\DSN\DSN;
use Utopia\Http\Http;
use Utopia\Registry\Registry;
use OpenRuntimes\Executor\Runner\Docker;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;

const MAX_LOG_SIZE = 5 * 1024 * 1024;

// Setup Registry
$register = new Registry();

$register->set('runner', function () {
    $orchestration = new Orchestration(new DockerAPI(
        Http::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', ''),
        Http::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '')
    ));

    $networks = explode(',', Http::getEnv('OPR_EXECUTOR_NETWORK') ?: 'openruntimes-runtimes');

    return new Docker($orchestration, $networks);
});

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


/** Resources */
Http::setResource('log', fn () => new Log());

Http::setResource('register', fn () => $register);

Http::setResource('logger', fn (Registry $register) => $register->get('logger'), ['register']);

Http::setResource('runner', fn (Registry $register) => $register->get('runner'), ['register']);
