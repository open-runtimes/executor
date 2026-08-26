<?php

declare(strict_types=1);

namespace Tests\Unit\Executor\Runner;

use OpenRuntimes\Executor\Exception as ExecutorException;
use OpenRuntimes\Executor\Runner\Docker;
use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use OpenRuntimes\Executor\Runner\Runtime;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Config\Config;
use Utopia\Orchestration\Orchestration;
use Utopia\System\System;

final class DockerCreateExecutionTimeoutTest extends TestCase
{
    private const int REPEAT = 8;

    protected function setUp(): void
    {
        Config::load('errors', \dirname(__DIR__, 4) . '/app/config/errors.php');
        $this->clearReadyTimeoutEnv();
    }

    protected function tearDown(): void
    {
        $this->clearReadyTimeoutEnv();
    }

    public function testRuntimeReadyTimeoutDefaultAndEnv(): void
    {
        $docker = $this->createFakeDocker(new Runtimes(16));
        $method = new ReflectionClass(Docker::class)->getMethod('getRuntimeReadyTimeout');

        $this->assertEqualsWithDelta(30.0, $method->invoke($docker), PHP_FLOAT_EPSILON);

        $this->setReadyTimeoutEnv('12');
        $this->assertEqualsWithDelta(12.0, $method->invoke($docker), PHP_FLOAT_EPSILON);

        $this->setReadyTimeoutEnv('0');
        $this->assertEqualsWithDelta(30.0, $method->invoke($docker), PHP_FLOAT_EPSILON);

        $this->setReadyTimeoutEnv('-5');
        $this->assertEqualsWithDelta(30.0, $method->invoke($docker), PHP_FLOAT_EPSILON);
    }

    public function testSlowPrepareAndLaunchDoesNotReduceHandlerTimeout(): void
    {
        for ($i = 0; $i < self::REPEAT; $i++) {
            $docker = $this->createFakeDocker(new Runtimes(16));
            $docker->prepareDelaySeconds = 1.2;
            $docker->launchDelaySeconds = 0.2;
            $docker->listenDelaySeconds = 0.2;

            $started = \microtime(true);
            $execution = $this->runCreateExecution($docker, 'slow-prepare-' . $i, timeout: 1);
            $elapsed = \microtime(true) - $started;

            $this->assertSame(200, $execution['statusCode']);
            $this->assertSame('OK', $execution['body']);
            $this->assertSame(1, $docker->seenHandlerTimeout, 'Handler must keep the original function timeout');
            $this->assertGreaterThan(1.4, $elapsed);
            $this->assertLessThan(5.0, $elapsed);
        }
    }

    public function testSlowListenDoesNotReduceHandlerTimeout(): void
    {
        for ($i = 0; $i < self::REPEAT; $i++) {
            $runtimes = new Runtimes(16);
            $docker = $this->createFakeDocker($runtimes);
            $runtimeId = 'slow-listen-' . $i;
            $docker->seedRuntime($runtimeId, status: 'Up 0.1s', listening: 0);
            $docker->listenDelaySeconds = 1.2;

            $execution = $this->runCreateExecution($docker, $runtimeId, timeout: 1);

            $this->assertSame(200, $execution['statusCode']);
            $this->assertSame(1, $docker->seenHandlerTimeout);
            $this->assertSame(0, $docker->createCalls);
        }
    }

    public function testHandlerTimeoutFailsAfterRuntimeIsListening(): void
    {
        for ($i = 0; $i < self::REPEAT; $i++) {
            $runtimes = new Runtimes(16);
            $docker = $this->createFakeDocker($runtimes);
            $runtimeId = 'handler-timeout-' . $i;
            $docker->seedRuntime($runtimeId, status: 'Up 0.1s', listening: 1);
            $docker->executionErrno = \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110;

            $started = \microtime(true);
            try {
                $this->runCreateExecution($docker, $runtimeId, timeout: 1, version: 'v2');
                $this->fail('Expected execution_timeout after the runtime was already listening');
            } catch (ExecutorException $exception) {
                $elapsed = \microtime(true) - $started;
                $this->assertSame(ExecutorException::EXECUTION_TIMEOUT, $exception->getType());
                $this->assertSame(1, $docker->seenHandlerTimeout);
                $this->assertLessThan(0.5, $elapsed, 'execution_timeout must fail immediately, not wait on cold start');
            }
        }
    }

    public function testRuntimeNeverReadyUsesReadyBudgetNotFunctionTimeout(): void
    {
        $this->setReadyTimeoutEnv('1');

        for ($i = 0; $i < self::REPEAT; $i++) {
            $runtimes = new Runtimes(16);
            $docker = $this->createFakeDocker($runtimes);
            $runtimeId = 'never-ready-' . $i;
            $docker->seedRuntime($runtimeId, status: 'Up 0.1s', listening: 0);
            $docker->neverListen = true;

            $started = \microtime(true);
            try {
                $this->runCreateExecution($docker, $runtimeId, timeout: 10);
                $this->fail('Expected runtime_timeout when the HTTP server never listens');
            } catch (ExecutorException $exception) {
                $elapsed = \microtime(true) - $started;
                $this->assertSame(ExecutorException::RUNTIME_TIMEOUT, $exception->getType());
                $this->assertGreaterThanOrEqual(0.9, $elapsed);
                $this->assertLessThan(3.0, $elapsed, 'Must not wait for the 10s function timeout');
                $this->assertSame(-1, $docker->seenHandlerTimeout, 'Handler must not run if the runtime never became ready');
            }
        }
    }

    public function testAlreadyListeningSkipsColdStartAndKeepsHandlerTimeout(): void
    {
        $runtimes = new Runtimes(16);
        $docker = $this->createFakeDocker($runtimes);
        $docker->seedRuntime('warm', status: 'Up 1s', listening: 1);

        $execution = $this->runCreateExecution($docker, 'warm', timeout: 7);

        $this->assertSame(200, $execution['statusCode']);
        $this->assertSame(7, $docker->seenHandlerTimeout);
        $this->assertSame(0, $docker->createCalls);
        $this->assertSame(0, $docker->listenCalls);
    }

    /**
     * @return array{statusCode: int, headers: mixed, body: mixed, logs: string, errors: string, duration: float, startTime: float}
     */
    private function runCreateExecution(FakeExecutionDocker $docker, string $runtimeId, int $timeout, string $version = 'v5'): array
    {
        /** @var array{statusCode: int, headers: mixed, body: mixed, logs: string, errors: string, duration: float, startTime: float} $execution */
        $execution = $docker->createExecution(
            $runtimeId,
            null,
            '/',
            'GET',
            [],
            $timeout,
            'openruntimes/node:v5-18.0',
            '/storage/functions/node/code.tar.gz',
            'index.js',
            [],
            1.0,
            512,
            $version,
            '',
            true,
            'no',
        );

        return $execution;
    }

    private function createFakeDocker(Runtimes $runtimes): FakeExecutionDocker
    {
        $orchestration = $this->createStub(Orchestration::class);

        return new FakeExecutionDocker($orchestration, $runtimes);
    }

    private function setReadyTimeoutEnv(string $value): void
    {
        \putenv('OPR_EXECUTOR_RUNTIME_READY_TIMEOUT=' . $value);
        $_ENV['OPR_EXECUTOR_RUNTIME_READY_TIMEOUT'] = $value;
        $_SERVER['OPR_EXECUTOR_RUNTIME_READY_TIMEOUT'] = $value;
    }

    private function clearReadyTimeoutEnv(): void
    {
        \putenv('OPR_EXECUTOR_RUNTIME_READY_TIMEOUT');
        unset($_ENV['OPR_EXECUTOR_RUNTIME_READY_TIMEOUT'], $_SERVER['OPR_EXECUTOR_RUNTIME_READY_TIMEOUT']);
    }
}

/**
 * @internal
 */
final class FakeExecutionDocker extends Docker
{
    public float $prepareDelaySeconds = 0.0;

    public float $launchDelaySeconds = 0.0;

    public float $listenDelaySeconds = 0.0;

    public bool $neverListen = false;

    public int $seenHandlerTimeout = -1;

    public int $createCalls = 0;

    public int $listenCalls = 0;

    public int $executionErrno = 0;

    private ?float $listenSince = null;

    /**
     * @param string[] $networks
     */
    public function __construct(Orchestration $orchestration, Runtimes $runtimes, array $networks = ['test'])
    {
        parent::__construct($orchestration, $runtimes, $networks);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{errNo: int, error: string, statusCode: int, executorResponse: mixed}
     */
    #[\Override]
    protected function sendCreateRuntimeRequest(array $params): array
    {
        $this->createCalls++;

        if ($this->prepareDelaySeconds > 0) {
            \usleep((int) ($this->prepareDelaySeconds * 1_000_000));
        }

        if ($this->launchDelaySeconds > 0) {
            \usleep((int) ($this->launchDelaySeconds * 1_000_000));
        }

        $this->seedRuntime((string) $params['runtimeId'], status: 'Up 0.1s', listening: 0);

        return [
            'errNo' => 0,
            'error' => '',
            'statusCode' => 201,
            'executorResponse' => '{}',
        ];
    }

    #[\Override]
    protected function isRuntimeListening(string $hostname): bool
    {
        $this->listenCalls++;

        if ($this->neverListen) {
            return false;
        }

        $this->listenSince ??= \microtime(true);

        return (\microtime(true) - $this->listenSince) >= $this->listenDelaySeconds;
    }

    /**
     * @param callable(): array{errNo: int, error: string, statusCode: int, body: mixed, logs: string, errors: string, headers: mixed} $executionRequest
     * @return array{errNo: int, error: string, statusCode: int, body: mixed, logs: string, errors: string, headers: mixed}
     */
    #[\Override]
    protected function dispatchExecution(callable $executionRequest, int $timeout): array
    {
        $this->seenHandlerTimeout = $timeout;

        if ($this->executionErrno !== 0) {
            return [
                'errNo' => $this->executionErrno,
                'error' => 'Operation timed out',
                'statusCode' => 0,
                'body' => '',
                'logs' => '',
                'errors' => '',
                'headers' => [],
            ];
        }

        return [
            'errNo' => \CURLE_OK,
            'error' => '',
            'statusCode' => 200,
            'body' => 'OK',
            'logs' => '',
            'errors' => '',
            'headers' => [],
        ];
    }

    public function seedRuntime(string $runtimeId, string $status = 'Up 0.1s', int $listening = 0): void
    {
        $name = System::getHostname() . '-' . $runtimeId;
        $now = \microtime(true);
        $runtimes = new ReflectionClass(Docker::class)->getProperty('runtimes')->getValue($this);
        \assert($runtimes instanceof Runtimes);

        $runtimes->set($name, new Runtime(
            version: 'v5',
            created: $now,
            updated: $now,
            name: $name,
            hostname: '127.0.0.1',
            status: $status,
            key: 'secret',
            listening: $listening,
            image: 'openruntimes/node:v5-18.0',
            initialised: 1,
        ));
    }
}
