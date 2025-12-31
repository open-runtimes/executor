<?php

use OpenRuntimes\Executor\Runner\Docker;
use OpenRuntimes\Executor\Runner\ImagePuller;
use OpenRuntimes\Executor\Runner\Maintenance;
use OpenRuntimes\Executor\Runner\Network;
use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use Utopia\Config\Config;
use Utopia\DI\Container;
use Utopia\DI\Dependency;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\DSN\DSN;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;
use Utopia\System\System;

const MAX_LOG_SIZE = 5 * 1024 * 1024;
const MAX_BUILD_LOG_SIZE = 1 * 1000 * 1000;

Config::load('errors', __DIR__ . '/config/errors.php');

// Setup DI Container
$container = new Container();

$runtimes = new Runtimes();
$runtimesDep = new Dependency();
$runtimesDep
    ->setName('runtimes')
    ->setCallback(fn () => $runtimes);
$container->set($runtimesDep);

$orchestration = new Dependency();
$orchestration
    ->setName('orchestration')
    ->setCallback(fn () => new Orchestration(new DockerAPI(
        System::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', ''),
        System::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '')
    )));
$container->set($orchestration);

$network = new Dependency();
$network
    ->setName('network')
    ->inject('orchestration')
    ->setCallback(fn (Orchestration $orchestration) => new Network($orchestration));
$container->set($network);

$imagePuller = new Dependency();
$imagePuller
    ->setName('imagePuller')
    ->inject('orchestration')
    ->setCallback(fn (Orchestration $orchestration) => new ImagePuller($orchestration));
$container->set($imagePuller);

$maintenance = new Dependency();
$maintenance
    ->setName('maintenance')
    ->inject('orchestration')
    ->inject('runtimes')
    ->setCallback(fn (Orchestration $orchestration, Runtimes $runtimes) => new Maintenance(
        $orchestration,
        $runtimes
    ));
$container->set($maintenance);

$runner = new Dependency();
$runner
    ->setName('runner')
    ->inject('orchestration')
    ->inject('runtimes')
    ->inject('networks')
    ->setCallback(fn (Orchestration $orchestration, Runtimes $runtimes, array $networks) => new Docker(
        $orchestration,
        $runtimes,
        $networks
    ));
$container->set($runner);

$logger = new Dependency();
$logger
    ->setName('logger')
    ->setCallback(function () {
        $providerName = System::getEnv('OPR_EXECUTOR_LOGGING_PROVIDER', '');
        $providerConfig = System::getEnv('OPR_EXECUTOR_LOGGING_CONFIG', '');

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
$container->set($logger);

$log = new Dependency();
$log
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
    });
$container->set($log);
