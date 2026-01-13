<?php

use Utopia\Config\Config;

const MAX_LOG_SIZE = 5 * 1024 * 1024;
const MAX_BUILD_LOG_SIZE = 1 * 1000 * 1000;

Config::load('errors', __DIR__ . '/config/errors.php');
