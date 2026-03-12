<?php

use OpenRuntimes\Executor\Runner\Docker;
use OpenRuntimes\Executor\Runner\ImagePuller;
use OpenRuntimes\Executor\Runner\Maintenance;
use OpenRuntimes\Executor\Runner\Network;
use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use OpenRuntimes\Executor\Runner\Adapter;
use Utopia\DI\Container;
use Utopia\DI\Dependency;
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

$registry->set('runtimes', fn (): Runtimes => new Runtimes());

$container->set(
    'runtimes',
    new Dependency([], fn (): Runtimes => $registry->get('runtimes'))
);

$container->set(
    'orchestration',
    new Dependency([], fn (): Orchestration => new Orchestration(new DockerAPI(
        System::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', ''),
        System::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '')
    )))
);

$container->set(
    'network',
    new Dependency(
        ['orchestration'],
        fn (Orchestration $orchestration): Network => new Network($orchestration)
    )
);

$container->set(
    'imagePuller',
    new Dependency(
        ['orchestration'],
        fn (Orchestration $orchestration): ImagePuller => new ImagePuller($orchestration)
    )
);

$container->set(
    'maintenance',
    new Dependency(
        ['orchestration', 'runtimes'],
        fn (Orchestration $orchestration, Runtimes $runtimes): Maintenance => new Maintenance($orchestration, $runtimes)
    )
);

$container->set(
    'runner',
    new Dependency(
        ['orchestration', 'runtimes', 'networks'],
        fn (Orchestration $orchestration, Runtimes $runtimes, array $networks): Adapter => new Docker($orchestration, $runtimes, $networks)
    )
);
