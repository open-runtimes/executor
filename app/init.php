<?php

use OpenRuntimes\Executor\Runner\Docker;
use OpenRuntimes\Executor\Runner\ImagePuller;
use OpenRuntimes\Executor\Runner\Maintenance;
use OpenRuntimes\Executor\Runner\Network;
use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use OpenRuntimes\Executor\Runner\Adapter;
use Utopia\DI\Container;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;
use Utopia\System\System;
use Utopia\Registry\Registry;
use Utopia\Config\Config;

const MAX_LOG_SIZE = 5 * 1024 * 1024;
const MAX_BUILD_LOG_SIZE = 1000 * 1000;

Config::load('errors', __DIR__ . '/config/errors.php');

$container = new Container();
$registry = new Registry();

$registry->set('runtimes', fn (): \OpenRuntimes\Executor\Runner\Repository\Runtimes => new Runtimes());

$container->setResource('runtimes', fn () => $registry->get('runtimes'));

$container->setResource('orchestration', fn (): Orchestration => new Orchestration(new DockerAPI(
    System::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', ''),
    System::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '')
)));

$container->setResource('network', fn (Orchestration $orchestration): Network => new Network($orchestration), ['orchestration']);

$container->setResource('imagePuller', fn (Orchestration $orchestration): ImagePuller => new ImagePuller($orchestration), ['orchestration']);

$container->setResource('maintenance', fn (Orchestration $orchestration, Runtimes $runtimes): Maintenance => new Maintenance($orchestration, $runtimes), ['orchestration', 'runtimes']);

$container->setResource('runner', fn (Orchestration $orchestration, Runtimes $runtimes, array $networks): Adapter => new Docker($orchestration, $runtimes, $networks), ['orchestration', 'runtimes', 'networks']);
