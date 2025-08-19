<?php

use Utopia\Config\Config;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\DSN\DSN;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\Registry\Registry;
use Utopia\System\System;

const MAX_LOG_SIZE = 5 * 1024 * 1024;
const MAX_BUILD_LOG_SIZE = 1 * 1000 * 1000;

Config::load('errors', __DIR__ . '/config/errors.php');

// Setup Registry
$register = new Registry();

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
Http::setResource('log', function (?Route $route) {
    $log = new Log();

    $log->setNamespace("executor");
    $log->setEnvironment(Http::isProduction() ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

    $version = (string) System::getEnv('OPR_EXECUTOR_VERSION', 'UNKNOWN');
    $log->setVersion($version);

    $server = System::getEnv('OPR_EXECUTOR_LOGGING_IDENTIFIER', \gethostname() ?: 'UNKNOWN');
    $log->setServer($server);

    if ($route) {
        $log->addTag('method', $route->getMethod());
        $log->addTag('url', $route->getPath());
    }

    return $log;
}, ['route']);

Http::setResource('register', fn () => $register);

Http::setResource('logger', fn (Registry $register) => $register->get('logger'), ['register']);
