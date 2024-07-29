<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Runtime;
use Swoole\Coroutine as Co;
use Utopia\CLI\Console;

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

// TODO: @Meldiron Write more tests (validators mainly)
// TODO: @Meldiron Health API tests
// TODO: Lengthy log test
// TODO: Lengthy body test

final class ExecutorTest extends TestCase
{
    protected Client $client;

    protected string $key;

    /**
     * @var string
     */
    protected $endpoint = 'http://executor/v1';

    protected function setUp(): void
    {
        $this->client = new Client();

        $this->key = 'executor-secret-key';

        $this->client
            ->setEndpoint($this->endpoint)
            ->addHeader('Content-Type', 'application/json')
            ->setKey($this->key);
    }

    public function testLogStream(): void
    {
        $runtimeLogs = '';
        $streamLogs = '';
        $totalChunks = 0;

        Co\run(function () use (&$runtimeLogs, &$streamLogs, &$totalChunks) {
            /** Prepare build */
            $output = '';
            Console::execute('cd /app/tests/resources/functions/node && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

            Co::join([
                /** Watch logs */
                Co\go(function () use (&$streamLogs, &$totalChunks) {
                    $this->client->call(Client::METHOD_GET, '/runtimes/test-log-stream/logs', [], [], true, function ($data) use (&$streamLogs, &$totalChunks) {
                        $streamLogs .= $data;
                        $totalChunks++;
                    });
                }),
                /** Start runtime */
                Co\go(function () use (&$runtimeLogs) {
                    $params = [
                        'runtimeId' => 'test-log-stream',
                        'source' => '/storage/functions/node/code.tar.gz',
                        'destination' => '/storage/builds/test-logs',
                        'entrypoint' => 'index.js',
                        'image' => 'openruntimes/node:v4-18.0',
                        'workdir' => '/usr/code',
                        'remove' => true,
                        'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm install && npm run build"'
                    ];

                    $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
                    $this->assertEquals(201, $response['headers']['status-code']);

                    $runtimeLogs = $response['body']['output'];
                }),
            ]);
        });

        $this->assertStringContainsString('Preparing for build', $runtimeLogs);
        $this->assertStringContainsString('Preparing for build', $streamLogs);

        $this->assertStringContainsString('Step: 1', $runtimeLogs);
        $this->assertStringContainsString('Step: 1', $streamLogs);

        $this->assertStringContainsString('Step: 2', $runtimeLogs);
        $this->assertStringContainsString('Step: 2', $streamLogs);

        $this->assertStringContainsString('Step: 30', $runtimeLogs);
        $this->assertStringContainsString('Step: 30', $streamLogs);

        $this->assertStringContainsString('Build finished', $runtimeLogs);
        $this->assertStringContainsString('Build finished', $streamLogs);

        $this->assertGreaterThan(3, $totalChunks);
    }

    public function testErrors(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/unknown', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Not Found', $response['body']['message']);

        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, count($response['body']));

        $response = $this->client->call(Client::METHOD_GET, '/runtimes/id', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);

        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);

        $this->client->setKey('');
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('Missing executor key', $response['body']['message']);
        $this->client->setKey($this->key);
    }

    /**
     * @return array<string,mixed>
     */
    public function testBuild(): array
    {
        $output = '';
        Console::execute('cd /app/tests/resources/functions/php && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build',
            'source' => '/storage/functions/php/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "composer install"'
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsString($response['body']['path']);
        $this->assertIsString($response['body']['output']);
        $this->assertIsFloat($response['body']['duration']);
        $this->assertIsFloat($response['body']['startTime']);
        $this->assertIsInt($response['body']['size']);

        $buildPath = $response['body']['path'];

        /** List runtimes */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, count($response['body']));
        $this->assertStringEndsWith('test-build', $response['body'][0]['name']);

        /** Get runtime */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes/test-build', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringEndsWith('test-build', $response['body']['name']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-build', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete non existent runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-build', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);

        /** Self-deleting build */
        $params['runtimeId'] = 'test-build-selfdelete';
        $params['remove'] = true;

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/runtimes/test-build-selfdelete', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);

        /** User error in build command */
        $params = [
            'runtimeId' => 'test-build-fail-400',
            'source' => '/storage/functions/php/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'cp doesnotexist.js doesnotexist2.js',
            'remove' => true
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(400, $response['headers']['status-code']);

        /** Test invalid path */
        $params = [
            'runtimeId' => 'test-build-fail-500',
            'source' => '/storage/fake_path/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "composer install"',
            'remove' => true
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(500, $response['headers']['status-code']);

        return ['path' => $buildPath];
    }

    /**
     * @depends testBuild
     *
     * @param array<string,mixed> $data
     */
    public function testExecute(array $data): void
    {
        $command = 'php src/server.php';
        $params = [
            'runtimeId' => 'test-exec',
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/executions');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);

        /** Execute on cold-started runtime */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/executions', [], [
            'body' => 'test payload',
            'variables' => [
                'customVariable' => 'mySecret'
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Execute on new runtime */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Execution without / at beginning of path */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'path' => 'v1/users',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("200", $response['body']['statusCode']);

        /** Execution with / at beginning of path */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("200", $response['body']['statusCode']);

        /** Execution with different accept headers */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'application/json'
        ], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/json', $response['headers']['content-type']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'application/*'
        ], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/json', $response['headers']['content-type']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'text/plain, application/json'
        ], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/json', $response['headers']['content-type']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'application/xml'
        ], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringStartsWith('multipart/form-data', $response['headers']['content-type']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'text/plain'
        ], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringStartsWith('multipart/form-data', $response['headers']['content-type']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => '*/*'
        ], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringStartsWith('multipart/form-data', $response['headers']['content-type']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec-coldstart', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testAutoRestart(): void
    {
        $output = '';
        Console::execute('cd /app/tests/resources/functions/php-exit && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        $command = 'php src/server.php';

        /** Build runtime */

        $params = [
            'runtimeId' => 'test-build-autorestart',
            'source' => '/storage/functions/php-exit/code.tar.gz',
            'destination' => '/storage/builds/test-autorestart',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh ""'
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $buildPath = $response['body']['path'];

        /** Execute function */

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-autorestart/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"',
            'autoRestart' => true
        ]);
        $this->assertEquals(500, $response['headers']['status-code']);

        \sleep(5);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-autorestart/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);
        $this->assertEquals(500, $response['headers']['status-code']);

        \sleep(5);

        /** Ensure autoRestart */

        $output = [];
        \exec('docker logs executor-test-exec-autorestart', $output);
        $output = \implode("\n", $output);
        $occurances = \substr_count($output, 'HTTP server successfully started!');
        $this->assertEquals(3, $occurances);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec-autorestart', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    /**
     *
     * @return array<mixed>
     */
    public function provideScenarios(): array
    {
        return [
            [
                'image' => 'openruntimes/node:v2-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-v2',
                'version' => 'v2',
                'startCommand' => '',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /usr/code && cd /usr/local/src/ && ./build.sh',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('{"message":"Hello Open Runtimes 👋"}', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v4-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-empty-object',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('{}', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v4-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-empty-array',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('[]', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v4-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-timeout',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(500, $response['body']['statusCode']);
                    $this->assertStringContainsString('Execution timed out.', $response['body']['errors']);
                    $this->assertEmpty($response['body']['logs']);
                }
            ],
            [
                'image' => 'openruntimes/node:v4-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-logs',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals("OK", $response['body']['body']);
                    $this->assertEquals(1 * 1024 * 1024, strlen($response['body']['logs']));
                    $this->assertEmpty($response['body']['errors']);
                },
                'body' => function () {
                    return '1';
                },
            ],
            [
                'image' => 'openruntimes/node:v4-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-logs',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals("OK", $response['body']['body']);
                    $this->assertEquals(5 * 1024 * 1024, strlen($response['body']['logs']));
                    $this->assertEmpty($response['body']['errors']);
                },
                'body' => function () {
                    return '5';
                },
            ],
            [
                'image' => 'openruntimes/node:v4-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-logs',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals("OK", $response['body']['body']);
                    $this->assertGreaterThan(5 * 1024 * 1024, strlen($response['body']['logs']));
                    $this->assertLessThan(6 * 1024 * 1024, strlen($response['body']['logs']));
                    $this->assertStringContainsString('truncated', $response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                },
                'body' => function () {
                    return '15';
                },
            ],
            [
                'image' => 'openruntimes/node:v4-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-logs',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals("OK", $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                },
                'body' => function () {
                    return '1';
                },
                'logging' => false,
            ],
            [
                'image' => 'openruntimes/node:v4-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-long-coldstart',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('OK', $response['body']['body']);
                    $this->assertGreaterThan(10, $response['body']['duration']); // This is unsafe but important. If its flaky, inform @Meldiron
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v4-21.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-binary-response',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $bytes = unpack('C*byte', $response['body']['body']);
                    if (!is_array($bytes)) {
                        $bytes = [];
                    }
                    self::assertCount(3, $bytes);
                    self::assertEquals(0, $bytes['byte1'] ?? 0);
                    self::assertEquals(10, $bytes['byte2'] ?? 0);
                    self::assertEquals(255, $bytes['byte3'] ?? 0);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                },
                null, // body,
                'logging' => true,
                'mimeType' => 'multipart/form-data'
            ],
            [
                'image' => 'openruntimes/node:v4-21.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-binary-response',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(400, $response['headers']['status-code']);
                    $this->assertStringContainsString("JSON response does not allow binaries", $response['body']['message']);
                },
                null, // body,
                'logging' => true,
                'mimeType' => 'application/json'
            ],
            [
                'image' => 'openruntimes/node:v4-21.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-binary-request',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $bytes = unpack('C*byte', $response['body']['body']);
                    if (!is_array($bytes)) {
                        $bytes = [];
                    }
                    self::assertCount(3, $bytes);
                    self::assertEquals(0, $bytes['byte1'] ?? 0);
                    self::assertEquals(10, $bytes['byte2'] ?? 0);
                    self::assertEquals(255, $bytes['byte3'] ?? 0);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                },
                'body' => function () {
                    return pack('C*', 0, 10, 255);
                },
                'logging' => true,
                'mimeType' => 'multipart/form-data'
            ],
        ];
    }

    /**
     * @param string $image
     * @param string $entrypoint
     * @param string $folder
     * @param string $version
     * @param string $startCommand
     * @param string $buildCommand
     * @param callable $assertions
     * @param bool $logging
     *
     * @dataProvider provideScenarios
     */
    public function testScenarios(string $image, string $entrypoint, string $folder, string $version, string $startCommand, string $buildCommand, callable $assertions, callable $body = null, bool $logging = true, string $mimeType = "application/json"): void
    {
        /** Prepare deployment */
        $output = '';
        Console::execute("cd /app/tests/resources/functions/{$folder} && tar --exclude code.tar.gz -czf code.tar.gz .", '', $output);

        /** Build runtime */
        $params = [
            'runtimeId' => "scenario-build-{$folder}",
            'source' => "/storage/functions/{$folder}/code.tar.gz",
            'destination' => '/storage/builds/test',
            'version' => $version,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'workdir' => '/usr/code',
            'remove' => true,
            'command' => $buildCommand
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $path = $response['body']['path'];

        $params = [
            'source' => $path,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'version' => $version,
            'runtimeEntrypoint' => $startCommand,
            'timeout' => 45,
            'logging' => $logging,
        ];

        if (isset($body)) {
            $params['body'] = $body();
        }

        /** Execute function */
        $response = $this->client->call(Client::METHOD_POST, "/runtimes/scenario-execute-{$folder}/executions", [
            'content-type' => $mimeType,
            'accept' => $mimeType
        ], $params);

        $this->assertStringContainsString($mimeType, $response['headers']['content-type']);

        call_user_func($assertions, $response);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, "/runtimes/scenario-execute-{$folder}", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    /**
     *
     * @return array<mixed>
     */
    public function provideCustomRuntimes(): array
    {
        return [
            [ 'folder' => 'php', 'image' => 'openruntimes/php:v4-8.1', 'entrypoint' => 'index.php', 'buildCommand' => 'composer install', 'startCommand' => 'php src/server.php' ],
            [ 'folder' => 'node', 'image' => 'openruntimes/node:v4-18.0', 'entrypoint' => 'index.js', 'buildCommand' => 'npm i', 'startCommand' => 'pm2 start src/server.js --no-daemon' ],
            // [ 'folder' => 'deno', 'image' => 'openruntimes/deno:v4-1.24', 'entrypoint' => 'index.ts', 'buildCommand' => 'deno cache index.ts', 'startCommand' => 'denon start' ],
            [ 'folder' => 'python', 'image' => 'openruntimes/python:v4-3.10', 'entrypoint' => 'index.py', 'buildCommand' => 'pip install --no-cache-dir -r requirements.txt', 'startCommand' => 'python3 src/server.py' ],
            [ 'folder' => 'ruby', 'image' => 'openruntimes/ruby:v4-3.1', 'entrypoint' => 'index.rb', 'buildCommand' => '', 'startCommand' => 'bundle exec puma -b tcp://0.0.0.0:3000 -e production' ],
            [ 'folder' => 'cpp', 'image' => 'openruntimes/cpp:v4-17', 'entrypoint' => 'index.cc', 'buildCommand' => '', 'startCommand' => 'src/function/cpp_runtime' ],
            [ 'folder' => 'dart', 'image' => 'openruntimes/dart:v4-2.18', 'entrypoint' => 'lib/index.dart', 'buildCommand' => 'dart pub get', 'startCommand' => 'src/function/server' ],
            [ 'folder' => 'dotnet', 'image' => 'openruntimes/dotnet:v4-6.0', 'entrypoint' => 'Index.cs', 'buildCommand' => '', 'startCommand' => 'dotnet src/function/DotNetRuntime.dll' ],
            // C++, Swift, Kotlin, Java missing on purpose
        ];
    }

    /**
     * @param string $folder
     * @param string $image
     * @param string $entrypoint
     *
     * @dataProvider provideCustomRuntimes
     */
    public function testCustomRuntimes(string $folder, string $image, string $entrypoint, string $buildCommand, string $startCommand): void
    {
        // Prepare tar.gz files
        $output = '';
        Console::execute("cd /app/tests/resources/functions/{$folder} && tar --exclude code.tar.gz -czf code.tar.gz .", '', $output);

        // Build deployment
        $params = [
            'runtimeId' => "custom-build-{$folder}",
            'source' => "/storage/functions/{$folder}/code.tar.gz",
            'destination' => '/storage/builds/test',
            'entrypoint' => $entrypoint,
            'image' => $image,
            'timeout' => 60,
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "' . $buildCommand . '"',
            'remove' => true
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        if ($response['headers']['status-code'] !== 201) {
            \var_dump($response);
        }
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsString($response['body']['path']);

        $path = $response['body']['path'];

        // Execute function
        $response = $this->client->call(Client::METHOD_POST, "/runtimes/custom-execute-{$folder}/executions", [], [
            'source' => $path,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $startCommand . '"',
            'timeout' => 60,
            'variables' => [
                'TEST_VARIABLE' => 'Variable secret'
            ],
            'path' => '/my-awesome/path?param=paramValue',
            'headers' => [
                'host' => 'cloud.appwrite.io',
                'x-forwarded-proto' => 'https',
                'content-type' => 'application/json'
            ],
            'body' => \json_encode([
                'id' => '2'
            ])
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $body = $response['body'];
        $this->assertEquals(200, $body['statusCode']);
        $this->assertEmpty($body['errors']);
        $this->assertStringContainsString('Sample Log', $body['logs']);
        $this->assertIsString($body['body']);
        $this->assertNotEmpty($body['body']);
        $response = \json_decode($body['body'], true);
        $this->assertEquals(true, $response['isTest']);
        $this->assertEquals('Hello Open Runtimes 👋', $response['message']);
        $this->assertEquals('Variable secret', $response['variable']);
        $this->assertEquals('https://cloud.appwrite.io/my-awesome/path?param=paramValue', $response['url']);
        $this->assertEquals(1, $response['todo']['userId']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, "/runtimes/custom-execute-{$folder}", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }
}
