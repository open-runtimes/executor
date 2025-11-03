<?php

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/error.php';
require_once __DIR__ . '/controllers.php';

use OpenRuntimes\Executor\Runner\Docker;
use OpenRuntimes\Executor\Runner\Kubernetes;
use Swoole\Runtime;
use Utopia\Console;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\System\System;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Adapter\K8s;
use Utopia\Orchestration\Orchestration;

use function Swoole\Coroutine\run;

// Unlimited memory limit to handle as many coroutines/requests as possible
ini_set('memory_limit', '-1');

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

Http::setMode((string)System::getEnv('OPR_EXECUTOR_ENV', Http::MODE_TYPE_PRODUCTION));

// Determine runner type from environment variable
$runnerType = System::getEnv('OPR_EXECUTOR_RUNNER', 'docker');

Http::onRequest()
    ->inject('response')
    ->action(function (Response $response) use ($runnerType) {
        $serverHeader = $runnerType === 'kubernetes' ? 'Executor-K8s' : 'Executor';
        $response->addHeader('Server', $serverHeader);
    });

run(function () use ($runnerType) {
    $networks = explode(',', System::getEnv('OPR_EXECUTOR_NETWORK') ?: 'openruntimes-runtimes');

    if ($runnerType === 'kubernetes') {
        Console::info("Initializing Kubernetes orchestration...");

        // Kubernetes in-cluster configuration
        $k8sUrl = System::getEnv('OPR_EXECUTOR_K8S_URL', 'https://kubernetes.default.svc');
        $k8sNamespace = System::getEnv('OPR_EXECUTOR_K8S_NAMESPACE', 'default');

        Console::info("K8s API URL: $k8sUrl");
        Console::info("K8s Namespace: $k8sNamespace");

        // Build authentication configuration
        $auth = [];

        // Try to read ServiceAccount token from the mounted secret (in-cluster)
        $tokenPath = '/var/run/secrets/kubernetes.io/serviceaccount/token';
        $caPath = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

        if (file_exists($tokenPath)) {
            $tokenContent = file_get_contents($tokenPath);
            if ($tokenContent !== false) {
                $auth['token'] = trim($tokenContent);
                Console::info("Using ServiceAccount token from: $tokenPath");
            }
        } elseif ($token = System::getEnv('OPR_EXECUTOR_K8S_TOKEN', '')) {
            // Fallback to environment variable (for external access)
            $auth['token'] = $token;
            Console::info("Using token from environment variable");
        }

        if (file_exists($caPath)) {
            $auth['ca'] = $caPath;
            Console::info("Using CA certificate from: $caPath");
        }

        if (empty($auth['token'])) {
            Console::warning("No authentication token found! Make sure ServiceAccount is properly configured.");
        }

        $orchestration = new Orchestration(new K8s($k8sUrl, $k8sNamespace ?? 'default', $auth));

        Console::info("Creating Kubernetes runner...");
        $runner = new Kubernetes($orchestration, $networks);

        Console::success('Kubernetes Executor is ready.');
    } else {
        Console::info("Initializing Docker orchestration...");

        $orchestration = new Orchestration(new DockerAPI(
            System::getEnv('OPR_EXECUTOR_DOCKER_HUB_USERNAME', ''),
            System::getEnv('OPR_EXECUTOR_DOCKER_HUB_PASSWORD', '')
        ));

        $runner = new Docker($orchestration, $networks);

        Console::success('Docker Executor is ready.');
    }

    Http::setResource('runner', fn () => $runner);

    $payloadSize = 22 * (1024 * 1024);
    $settings = [
        'package_max_length' => $payloadSize,
        'buffer_output_size' => $payloadSize,
    ];

    $server = new Server('0.0.0.0', '80', $settings);
    $http = new Http($server, 'UTC');

    $http->start();
});
