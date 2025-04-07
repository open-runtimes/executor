<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine as Co;
use Utopia\CLI\Console;

// TODO: @Meldiron Write more tests (validators mainly)
// TODO: @Meldiron Health API tests
// TODO: Lengthy log test
// TODO: Lengthy body test

class ExecutorTest extends TestCase
{
    protected Client $client;
    protected string $endpoint = 'http://executor/v1';
    protected string $key = 'executor-secret-key';

    protected function setUp(): void
    {
        $this->client = new Client();

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

        $runtimeId = \bin2hex(\random_bytes(4));

        Co\run(function () use (&$runtimeLogs, &$streamLogs, &$totalChunks, $runtimeId) {
            /** Prepare build */
            $output = '';
            Console::execute('cd /app/tests/resources/functions/node && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

            Co::join([
                /** Watch logs */
                Co\go(function () use (&$streamLogs, &$totalChunks, $runtimeId) {
                    $this->client->call(Client::METHOD_GET, '/runtimes/test-log-stream-'.$runtimeId.'/logs', [], [], true, function ($data) use (&$streamLogs, &$totalChunks) {
                        $streamLogs .= $data;
                        $totalChunks++;
                    });
                }),
                /** Start runtime */
                Co\go(function () use (&$runtimeLogs, $runtimeId) {
                    $params = [
                        'runtimeId' => 'test-log-stream-' . $runtimeId,
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

        for ($i = 1; $i <= 30; $i++) {
            $this->assertStringContainsString("Step: $i", $runtimeLogs);
            $this->assertStringContainsString("Step: $i", $streamLogs);
        }

        $this->assertStringContainsString('Build finished', $runtimeLogs);
        $this->assertStringContainsString('Build finished', $streamLogs);

        // Chunking is controlled by the adapter implementation, just verify we got more than 1 chunk.
        $this->assertGreaterThan(1, $totalChunks);
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

    public function testBuild(): void
    {
        $output = '';
        Console::execute('cd /app/tests/resources/functions/php && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        $runtimeId = \bin2hex(\random_bytes(4));

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-' . $runtimeId,
            'source' => '/storage/functions/php/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "composer install"',
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsString($response['body']['path']);
        $this->assertIsString($response['body']['output']);
        $this->assertIsFloat($response['body']['duration']);
        $this->assertIsFloat($response['body']['startTime']);
        $this->assertIsInt($response['body']['size']);

        /** Ensure build folder exists (container still running) */
        $tmpFolderPath = '/tmp/executor-test-build-' . $runtimeId;
        $this->assertTrue(\is_dir($tmpFolderPath));
        $this->assertTrue(\file_exists($tmpFolderPath));

        /** List runtimes */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, count($response['body']));
        $this->assertStringEndsWith('test-build-' . $runtimeId, $response['body'][0]['name']);

        /** Get runtime */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes/test-build-' . $runtimeId, [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringEndsWith('test-build-' . $runtimeId, $response['body']['name']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-build-' . $runtimeId, [], []);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete non existent runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-build-' . $runtimeId, [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);

        /** Self-deleting build */
        $params['runtimeId'] = 'test-build-selfdelete-' . $runtimeId;
        $params['remove'] = true;

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        /** Ensure build folder cleanup */
        $tmpFolderPath = '/tmp/executor-test-build-selfdelete-' . $runtimeId;
        $this->assertFalse(\is_dir($tmpFolderPath));
        $this->assertFalse(\file_exists($tmpFolderPath));

        /** List runtimes */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, count($response['body'])); // Not 1, it was auto-removed

        /** Failure getRuntime */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes/test-build-selfdelete-' . $runtimeId, [], []);
        $this->assertEquals(404, $response['headers']['status-code']);

        /** User error in build command */
        $params = [
            'runtimeId' => 'test-build-fail-400-' . $runtimeId,
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
            'runtimeId' => 'test-build-fail-500-' . $runtimeId,
            'source' => '/storage/fake_path/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "composer install"',
            'remove' => true
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(500, $response['headers']['status-code']);
    }

    public function testBuildOutputDirectory(): void
    {
        $output = '';
        Console::execute('cd /app/tests/resources/functions/static && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-site-' . \bin2hex(\random_bytes(4)),
            'source' => '/storage/functions/static/code.tar.gz',
            'destination' => '/storage/builds/test',
            'image' => 'openruntimes/static:v4-1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "sh build.sh"',
            'outputDirectory' => './dist',
            'remove' => true,
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsString($response['body']['path']);
        $this->assertIsString($response['body']['output']);
        $this->assertIsFloat($response['body']['duration']);
        $this->assertIsFloat($response['body']['startTime']);
        $this->assertIsInt($response['body']['size']);

        $buildPath = $response['body']['path'];

        /** Test executions */
        $command = 'sh helpers/server.sh';
        $runtimeId = \bin2hex(\random_bytes(4));
        $params = [
            'runtimeId' => 'test-exec-site-' . $runtimeId,
            'source' => $buildPath,
            'image' => 'openruntimes/static:v4-1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-site-'.$runtimeId.'/executions', [
            'path' => '/index.html'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec-site-'.$runtimeId, [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testExecute(): void
    {
        /** Prepare function */
        $output = '';
        Console::execute('cd /app/tests/resources/functions/php && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        $params = [
            'runtimeId' => 'test-build',
            'source' => '/storage/functions/php/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "composer install"',
            'remove' => true,
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty(201, $response['body']['path']);

        $buildPath = $response['body']['path'];

        /** Test executions */
        $command = 'php src/server.php';
        $params = [
            'runtimeId' => 'test-exec',
            'source' => $buildPath,
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
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Execution without / at beginning of path */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'path' => 'v1/users',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("200", $response['body']['statusCode']);

        /** Execution with / at beginning of path */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [], [
            'source' => $buildPath,
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
            'source' => $buildPath,
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
            'source' => $buildPath,
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
            'source' => $buildPath,
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
            'source' => $buildPath,
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
            'source' => $buildPath,
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
            'source' => $buildPath,
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

    public function testRestartPolicy(): void
    {
        $output = '';
        Console::execute('cd /app/tests/resources/functions/php-exit && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        $command = 'php src/server.php';

        /** Build runtime */

        $params = [
            'runtimeId' => 'test-build-restart-policy',
            'source' => '/storage/functions/php-exit/code.tar.gz',
            'destination' => '/storage/builds/test-restart-policy',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh ""'
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $buildPath = $response['body']['path'];

        /** Execute function */

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-restart-policy/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"',
            'restartPolicy' => 'always'
        ]);
        $this->assertEquals(500, $response['headers']['status-code']);

        \sleep(5);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-restart-policy/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);
        $this->assertEquals(500, $response['headers']['status-code']);

        \sleep(5);

        /** Ensure restart policy */

        $output = [];
        \exec('docker logs executor-test-exec-restart-policy', $output);
        $output = \implode("\n", $output);
        $occurances = \substr_count($output, 'HTTP server successfully started!');
        $this->assertEquals(3, $occurances);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec-restart-policy', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testBuildLogLimit(): void
    {
        $size128Kb = 1024 * 128;

        $output = '';
        Console::execute('cd /app/tests/resources/functions/php-build-logs && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-logs-' . \bin2hex(\random_bytes(4)),
            'source' => '/storage/functions/php-build-logs/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "sh logs_failure.sh"',
            'remove' => true
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual($size128Kb * 7, \strlen($response['body']['message']));
        $this->assertStringContainsString('Preparing for build ...', $response['body']['message']);
        $this->assertStringContainsString('Build exited.', $response['body']['message']);

        $output = '';
        Console::execute('cd /app/tests/resources/functions/php-build-logs && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-logs-' . \bin2hex(\random_bytes(4)),
            'source' => '/storage/functions/php-build-logs/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "sh logs_success.sh"',
            'remove' => true
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual($size128Kb * 7, \strlen($response['body']['output']));
        $this->assertStringContainsString('Preparing for build ...', $response['body']['output']);
        $this->assertStringContainsString('Build finished.', $response['body']['output']);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-logs-' . \bin2hex(\random_bytes(4)),
            'source' => '/storage/functions/php-build-logs/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "sh logs_failure_large.sh"',
            'remove' => true
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(1000000, \strlen($response['body']['message']));
        $this->assertStringNotContainsString('Preparing for build ...', $response['body']['message']);
        $this->assertStringContainsString('Build exited.', $response['body']['message']);

        $output = '';
        Console::execute('cd /app/tests/resources/functions/php-build-logs && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-logs-' . \bin2hex(\random_bytes(4)),
            'source' => '/storage/functions/php-build-logs/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "sh logs_success_large.sh"',
            'remove' => true
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals(1000000, \strlen($response['body']['output']));
        $this->assertStringNotContainsString('Preparing for build ...', $response['body']['output']);
        $this->assertStringContainsString('Build finished.', $response['body']['output']);
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
            [
                'image' => 'openruntimes/node:v4-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-specs',
                'version' => 'v4',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "pm2 start src/server.js --no-daemon"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && helpers/build.sh "npm i && npm run build"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);

                    $this->assertIsString($response['body']['body']);
                    $this->assertNotEmpty($response['body']['body']);
                    $json = \json_decode($response['body']['body'], true);
                    $this->assertEquals("2.5", $json['cpus']);
                    $this->assertEquals("1024", $json['memory']);

                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                },
                'body' => null,
                'logging' => true,
                'mimeType' => 'application/json',
                'cpus' => 2.5,
                'memory' => 1024,
                'buildAssertions' => function ($response) {
                    $this->assertStringContainsString("cpus=2.5", $response['body']['output']);
                    $this->assertStringContainsString("memory=1024", $response['body']['output']);
                }
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
    public function testScenarios(string $image, string $entrypoint, string $folder, string $version, string $startCommand, string $buildCommand, callable $assertions, callable $body = null, bool $logging = true, string $mimeType = "application/json", float $cpus = 1, int $memory = 512, callable $buildAssertions = null): void
    {
        /** Prepare deployment */
        $output = '';
        Console::execute("cd /app/tests/resources/functions/{$folder} && tar --exclude code.tar.gz -czf code.tar.gz .", '', $output);

        $runtimeId = \bin2hex(\random_bytes(4));

        /** Build runtime */
        $params = [
            'runtimeId' => "scenario-build-{$folder}-{$runtimeId}",
            'source' => "/storage/functions/{$folder}/code.tar.gz",
            'destination' => '/storage/builds/test',
            'version' => $version,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'workdir' => '/usr/code',
            'remove' => true,
            'command' => $buildCommand,
            'cpus' => $cpus,
            'memory' => $memory
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        if (!is_null($buildAssertions)) {
            call_user_func($buildAssertions, $response);
        }

        $path = $response['body']['path'];

        $params = [
            'source' => $path,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'version' => $version,
            'runtimeEntrypoint' => $startCommand,
            'timeout' => 45,
            'logging' => $logging,
            'cpus' => $cpus,
            'memory' => $memory,
        ];

        if (isset($body)) {
            $params['body'] = $body();
        }

        /** Execute function */
        $response = $this->client->call(Client::METHOD_POST, "/runtimes/scenario-execute-{$folder}-{$runtimeId}/executions", [
            'content-type' => $mimeType,
            'accept' => $mimeType
        ], $params);

        $this->assertStringContainsString($mimeType, $response['headers']['content-type']);

        call_user_func($assertions, $response);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, "/runtimes/scenario-execute-{$folder}-{$runtimeId}", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }


    /**
     *
     * @return array<mixed>
     */
    public function provideCustomRuntimes(): array
    {
        return [
            [ 'folder' => 'php', 'image' => 'openruntimes/php:v4-8.1', 'entrypoint' => 'index.php', 'buildCommand' => 'composer install' ],
            [ 'folder' => 'node', 'image' => 'openruntimes/node:v4-18.0', 'entrypoint' => 'index.js', 'buildCommand' => 'npm i'],
            // [ 'folder' => 'deno', 'image' => 'openruntimes/deno:v4-1.24', 'entrypoint' => 'index.ts', 'buildCommand' => 'deno cache index.ts', 'startCommand' => 'denon start' ],
            [ 'folder' => 'python', 'image' => 'openruntimes/python:v4-3.10', 'entrypoint' => 'index.py', 'buildCommand' => 'pip install -r requirements.txt'],
            [ 'folder' => 'ruby', 'image' => 'openruntimes/ruby:v4-3.1', 'entrypoint' => 'index.rb', 'buildCommand' => ''],
            [ 'folder' => 'cpp', 'image' => 'openruntimes/cpp:v4-17', 'entrypoint' => 'index.cc', 'buildCommand' => ''],
            [ 'folder' => 'dart', 'image' => 'openruntimes/dart:v4-2.18', 'entrypoint' => 'lib/index.dart', 'buildCommand' => 'dart pub get'],
            [ 'folder' => 'dotnet', 'image' => 'openruntimes/dotnet:v4-6.0', 'entrypoint' => 'Index.cs', 'buildCommand' => ''],
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
    public function testCustomRuntimes(string $folder, string $image, string $entrypoint, string $buildCommand): void
    {
        // Prepare tar.gz files
        $output = '';
        Console::execute("cd /app/tests/resources/functions/{$folder} && tar --exclude code.tar.gz -czf code.tar.gz .", '', $output);

        $runtimeId = \bin2hex(\random_bytes(4));

        // Build deployment
        $params = [
            'runtimeId' => "custom-build-{$folder}-{$runtimeId}",
            'source' => "/storage/functions/{$folder}/code.tar.gz",
            'destination' => '/storage/builds/test',
            'entrypoint' => $entrypoint,
            'image' => $image,
            'timeout' => 120,
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
        $response = $this->client->call(Client::METHOD_POST, "/runtimes/custom-execute-{$folder}-{$runtimeId}/executions", [], [
            'source' => $path,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh helpers/server.sh',
            'timeout' => 120,
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
        $response = $this->client->call(Client::METHOD_DELETE, "/runtimes/custom-execute-{$folder}-{$runtimeId}", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testZipBuild(): void
    {
        /** Prepare function */
        $output = '';
        Console::execute('cd /app/tests/resources/functions/php && zip -x code.zip -r code.zip .', '', $output);

        $runtimeId = \bin2hex(\random_bytes(4));

        $params = [
            'remove' => true,
            'runtimeId' => 'test-build-zip-' . $runtimeId,
            'source' => '/storage/functions/php/code.zip',
            'destination' => '/storage/builds/test-zip',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'command' => 'unzip /tmp/code.tar.gz -d /mnt/code && helpers/build.sh "composer install"',
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty(201, $response['body']['path']);

        $buildPath = $response['body']['path'];

        /** Test executions */
        $command = 'php src/server.php';
        $params = [
            'runtimeId' => 'test-exec-zip-' . $runtimeId,
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v4-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-zip-' . $runtimeId .'/executions');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec-zip-' . $runtimeId, [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }
}
