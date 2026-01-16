<?php

use OpenRuntimes\Executor\Runner\Docker;
use OpenRuntimes\Executor\Runner\ImagePuller;
use OpenRuntimes\Executor\Runner\Maintenance;
use OpenRuntimes\Executor\Runner\Network;
use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use OpenRuntimes\Executor\Runner\Adapter;
use Utopia\Http\Http;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;
use Utopia\System\System;
use Utopia\Registry\Registry;
use Utopia\Config\Config;

const MAX_LOG_SIZE = 5 * 1024 * 1024;
const MAX_BUILD_LOG_SIZE = 1 * 1000 * 1000;

Config::load('errors', __DIR__ . '/config/errors.php');

$registry = new Registry();

$registry->set('runtimes', fn () => new Runtimes());

Http::setResource('runtimes', fn () => $registry->get('runtimes'));

Http::setResource('orchestration', fn (): Orchestration => new Orchestration(new DockerAPI(
    System::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', ''),
    System::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '')
)));

Http::setResource('network', fn (Orchestration $orchestration): Network => new Network($orchestration), ['orchestration']);

Http::setResource('imagePuller', fn (Orchestration $orchestration): ImagePuller => new ImagePuller($orchestration), ['orchestration']);

Http::setResource('maintenance', fn (Orchestration $orchestration, Runtimes $runtimes): Maintenance => new Maintenance($orchestration, $runtimes), ['orchestration', 'runtimes']);

Http::setResource('runner', fn (Orchestration $orchestration, Runtimes $runtimes, array $networks): Adapter => new Docker($orchestration, $runtimes, $networks), ['orchestration', 'runtimes', 'networks']);
