<?php

use OpenRuntimes\Executor\Runner\Docker;
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
use Utopia\DI\Container;
use Utopia\DI\Dependency;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;

const MAX_LOG_SIZE = 5 * 1024 * 1024;
const MAX_BUILD_LOG_SIZE = 1 * 1000 * 1000;

Config::load('errors', __DIR__ . '/config/errors.php');

// Setup Register
$register = new Registry();

$register->set('logger', function () {
    $name = System::getEnv('OPR_EXECUTOR_LOGGING_PROVIDER', '');
    $config = System::getEnv('OPR_EXECUTOR_LOGGING_CONFIG', '');

    try {
        $dsn = new DSN($config ?? '');

        $name = $dsn->getScheme();
        $config = match ($name) {
            'sentry' => ['key' => $dsn->getPassword(), 'projectId' => $dsn->getUser() ?? '', 'host' => 'https://' . $dsn->getHost()],
            'logowl' => ['ticket' => $dsn->getUser() ?? '', 'host' => $dsn->getHost()],
            default => ['key' => $dsn->getHost()],
        };
    } catch (Throwable) {
        $chunks = \explode(";", $config ?? '');

        $config = match ($name) {
            'sentry' => ['key' => $chunks[0], 'projectId' => $chunks[1] ?? '', 'host' => ''],
            'logowl' => ['ticket' => $chunks[0] ?? '', 'host' => ''],
            default => ['key' => $config],
        };
    }

    $logger = null;

    if (!empty($name) && is_array($config) && Logger::hasProvider($name)) {
        $adapter = match ($name) {
            'sentry' => new Sentry($config['projectId'] ?? '', $config['key'] ?? '', $config['host'] ?? ''),
            'logowl' => new LogOwl($config['ticket'] ?? '', $config['host'] ?? ''),
            'raygun' => new Raygun($config['key'] ?? ''),
            'appsignal' => new AppSignal($config['key'] ?? ''),
            default => throw new Exception("Provider $name not supported.")
        };

        $logger = new Logger($adapter);
    }

    return $logger;
});

$container = new Container();

$container->set(
    (new Dependency())
    ->setName('register')
    ->setCallback(fn () => $register)
);

$container->set(
    (new Dependency())
    ->setName('log')
    ->inject('route')
    ->setCallback(function (?Route $route) {
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
    })
);

$container->set(
    (new Dependency())
    ->setName('network')
    ->setCallback(fn () => explode(',', System::getEnv('OPR_EXECUTOR_NETWORK') ?: 'openruntimes-runtimes'))
);

$container->set(
    (new Dependency())
    ->setName('logger')
    ->inject('register')
    ->setCallback(fn (Registry $registry) => $registry->get('logger'))
);

$container->set(
    (new Dependency())
    ->setName('orchestration')
    ->setCallback(fn () => new Orchestration(new DockerAPI(
        System::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', ''),
        System::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '')
    )))
);

$container->set(
    (new Dependency())
    ->setName('runner')
    ->inject('orchestration')
    ->inject('network')
    ->setCallback(fn (Orchestration $orchestration, array $networks) => new Docker($orchestration, $networks))
);
