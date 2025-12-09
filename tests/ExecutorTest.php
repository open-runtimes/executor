<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Fetch\Client;
use Swoole\Coroutine as Co;
use Utopia\Console;
use OpenRuntimes\Executor\BodyMultipart;

// TODO: @Meldiron Write more tests (validators mainly)
// TODO: @Meldiron Health API tests
// TODO: Lengthy log test
// TODO: Lengthy body test

class ExecutorTest extends TestCase
{
    protected string $endpoint = 'http://executor/v1';
    protected string $key = 'executor-secret-key';

    /**
     * Base headers that are added to every request
     * @var array<string, string>
     */
    protected array $baseHeaders = [];

    protected function setUp(): void
    {
        $this->baseHeaders = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->key,
            'x-executor-response-format' => '0.11.0' // Enable array support for duplicate headers
        ];
    }

    /**
     * Set the executor key
     * @param string $key
     * @return void
     */
    protected function setKey(string $key): void
    {
        $this->baseHeaders['Authorization'] = 'Bearer ' . $key;
    }

    /**
     * Wrapper method for client calls to make requests to the executor
     *
     * @param string $method
     * @param string $path
     * @param array<string, string> $headers
     * @param array<string, mixed> $params
     * @param bool $decode
     * @param ?callable $callback
     * @return array<string, mixed>
     */
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, ?callable $callback = null): array
    {
        $url = $this->endpoint . $path;

        $client = new Client();
        $client->setTimeout(60);

        foreach ($this->baseHeaders as $key => $value) {
            $client->addHeader($key, $value);
        }
        foreach ($headers as $key => $value) {
            $client->addHeader($key, $value);
        }

        $response = $client->fetch(
            url: $url,
            method: $method,
            body: $method !== Client::METHOD_GET ? $params : [],
            query: $method === Client::METHOD_GET ? $params : [],
            chunks: $callback ? function ($chunk) use ($callback) {
                $callback($chunk->getData());
            } : null
        );

        $body = null;
        if ($callback === null) {
            if ($decode) {
                $contentType = $response->getHeaders()['content-type'] ?? '';
                $strpos = strpos($contentType, ';');
                $strpos = is_bool($strpos) ? strlen($contentType) : $strpos;
                $contentType = substr($contentType, 0, $strpos);

                switch ($contentType) {
                    case 'multipart/form-data':
                        $boundary = explode('boundary=', $response->getHeaders()['content-type'] ?? '')[1] ?? '';
                        $multipartResponse = new BodyMultipart($boundary);
                        $multipartResponse->load($response->text());
                        $body = $multipartResponse->getParts();
                        break;
                    case 'application/json':
                        $body = $response->json();
                        break;
                    default:
                        $body = $response->text();
                        break;
                }
            } else {
                $body = $response->text();
            }
        }

        $result = [
            'headers' => array_merge(
                $response->getHeaders(),
                ['status-code' => $response->getStatusCode()]
            ),
            'body' => $body
        ];

        return $result;
    }

    public function testLogStream(): void
    {
        $runtimeChunks = [];
        $streamChunks = [];

        $runtimeId = \bin2hex(\random_bytes(4));

        Co\run(function () use (&$runtimeChunks, &$streamChunks, $runtimeId) {
            /** Prepare build */
            $output = '';
            Console::execute('cd /app/tests/resources/functions/node && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

            Co::join([
                /** Watch logs */
                Co\go(function () use (&$streamChunks, $runtimeId) {
                    $this->call(Client::METHOD_GET, '/runtimes/test-log-stream-' . $runtimeId . '/logs', [], [], true, function ($data) use (&$streamChunks) {
                        // stream log parsing
                        $data = \str_replace("\\n", "{OPR_LINEBREAK_PLACEHOLDER}", $data);
                        foreach (\explode("\n", $data) as $chunk) {
                            if (empty($chunk)) {
                                continue;
                            }

                            $chunk = \str_replace("{OPR_LINEBREAK_PLACEHOLDER}", "\n", $chunk);
                            $parts = \explode(" ", $chunk, 2);
                            $streamChunks[] = [
                                'timestamp' => $parts[0] ?? '',
                                'content' => $parts[1] ?? ''
                            ];
                        }
                    });
                }),
                /** Start runtime */
                Co\go(function () use (&$runtimeChunks, $runtimeId) {
                    $params = [
                        'runtimeId' => 'test-log-stream-' . $runtimeId,
                        'source' => '/storage/functions/node/code.tar.gz',
                        'destination' => '/storage/builds/test-logs',
                        'entrypoint' => 'index.js',
                        'image' => 'openruntimes/node:v5-18.0',
                        'workdir' => '/usr/code',
                        'remove' => true,
                        'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm install && npm run build"'
                    ];

                    $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
                    $this->assertEquals(201, $response['headers']['status-code']);

                    $runtimeChunks = $response['body']['output'];
                }),
            ]);
        });

        // Realtime logs are non-strict regarding exact end of logs
        $validateLogs = function (array $logs, bool $strict) {
            $content = '';
            foreach ($logs as $log) {
                $content .= $log['content'];
            }

            $this->assertStringContainsString('Environment preparation started', $content);

            for ($i = 1; $i <= 10; $i++) {
                $this->assertStringContainsString("Step: $i", $content);
            }

            if ($strict) {
                $this->assertStringContainsString('Build finished', $content);
            }

            $this->assertGreaterThan(5, \count($logs));

            $hasOrange = false;
            $hasRed = false;
            $hasStep = false;

            $previousTimestamp = null;
            $firstTimestamp = null;
            foreach ($logs as $log) {
                $this->assertNotEmpty($log['content']);
                $this->assertNotEmpty($log['timestamp']);

                if (!\is_null($previousTimestamp)) {
                    $this->assertGreaterThanOrEqual($previousTimestamp, $log['timestamp']);
                } else {
                    $firstTimestamp = null;
                }

                $previousTimestamp = $log['timestamp'];

                if (!(\str_contains($log['content'], "echo -e"))) {
                    if (\str_contains($log['content'], "[33mOrange message") && \str_contains($log['content'], "[0m")) {
                        $hasOrange = true;
                        continue;
                    }
                    if (\str_contains($log['content'], "[31mRed message") && \str_contains($log['content'], "[0m")) {
                        $hasRed = true;
                        continue;
                    }
                }

                if (\str_contains($log['content'], "Step: 5")) {
                    $hasStep = true;
                }
            }

            $this->assertGreaterThanOrEqual($firstTimestamp, $previousTimestamp);

            $this->assertTrue($hasRed);
            $this->assertTrue($hasOrange);
            $this->assertTrue($hasStep);
        };

        // TODO: Below 2 assertions are due to merge conflict. I want to remove but not sure if I can, so keeping it for now
        // Chunking is controlled by the adapter implementation, just verify we got more than 1 chunk.
        $this->assertGreaterThan(1, $runtimeChunks);
        $this->assertGreaterThan(1, $streamChunks);

        $validateLogs($runtimeChunks, true);
        $validateLogs($streamChunks, false);
    }

    public function testUnknownPath(): void
    {
        $response = $this->call(Client::METHOD_GET, '/unknown', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Not Found', $response['body']['message']);
    }

    public function testGetRuntimesEmpty(): void
    {
        $response = $this->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, count($response['body']));
    }

    public function testGetRuntimeUnknown(): void
    {
        $response = $this->call(Client::METHOD_GET, '/runtimes/id', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);
    }

    public function testDeleteRuntimeUnknown(): void
    {
        $response = $this->call(Client::METHOD_DELETE, '/runtimes/test', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);
    }

    public function testGetRuntimesUnauthorized(): void
    {
        $this->setKey('');
        $response = $this->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('Missing executor key', $response['body']['message']);
        $this->setKey($this->key);
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
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "composer install"',
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        /** Ensure build folder cleanup */
        $tmpFolderPath = '/tmp/executor-test-build-' . $runtimeId;
        $this->assertFalse(\is_dir($tmpFolderPath));
        $this->assertFalse(\file_exists($tmpFolderPath));

        /** List runtimes */
        $response = $this->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, count($response['body'])); // Not 1, it was auto-removed

        /** Failure getRuntime */
        $response = $this->call(Client::METHOD_GET, '/runtimes/test-build-selfdelete-' . $runtimeId, [], []);
        $this->assertEquals(404, $response['headers']['status-code']);

        /** User error in build command */
        $params = [
            'runtimeId' => 'test-build-fail-400-' . $runtimeId,
            'source' => '/storage/functions/php/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'cp doesnotexist.js doesnotexist2.js',
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(400, $response['headers']['status-code']);

        /** Test invalid path */
        $params = [
            'runtimeId' => 'test-build-fail-500-' . $runtimeId,
            'source' => '/storage/fake_path/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "composer install"',
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
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
            'image' => 'openruntimes/static:v5-1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "bash build.sh"',
            'outputDirectory' => './dist',
            'remove' => true,
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsString($response['body']['path']);
        $this->assertIsArray($response['body']['output']);
        $this->assertIsFloat($response['body']['duration']);
        $this->assertIsFloat($response['body']['startTime']);
        $this->assertIsInt($response['body']['size']);

        $buildPath = $response['body']['path'];

        /** Test executions */
        $command = 'bash helpers/server.sh';
        $runtimeId = \bin2hex(\random_bytes(4));
        $params = [
            'runtimeId' => 'test-exec-site-' . $runtimeId,
            'source' => $buildPath,
            'image' => 'openruntimes/static:v5-1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-site-'.$runtimeId.'/executions', [
            'path' => '/index.html'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);

        /** Delete runtime */
        $response = $this->call(Client::METHOD_DELETE, '/runtimes/test-exec-site-'.$runtimeId, [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testBuildUncompressed(): void
    {
        $output = '';
        Console::execute('cd /app/tests/resources/functions/node && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        $runtimeId = \bin2hex(\random_bytes(4));

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-' . $runtimeId,
            'source' => '/storage/functions/node/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.js',
            'image' => 'openruntimes/node:v5-22',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm install"',
            'variables' => [
                'OPEN_RUNTIMES_BUILD_COMPRESSION' => 'none'
            ],
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['path']);

        $buildPath = $response['body']['path'];

        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-' . $runtimeId . '/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.js',
            'image' => 'openruntimes/node:v5-22',
            'runtimeEntrypoint' => 'cp /tmp/code.tar /mnt/code/code.tar && nohup helpers/start.sh "bash helpers/server.sh"',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);
        $json = json_decode($response['body']['body'], true);
        $this->assertEquals("Hello Open Runtimes 👋", $json['message']);

        $response = $this->call(Client::METHOD_DELETE, '/runtimes/test-exec-' . $runtimeId, [], []);
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
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "composer install"',
            'remove' => true,
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['path']);

        $buildPath = $response['body']['path'];

        /** Test executions */
        $command = 'php src/server.php';
        $params = [
            'runtimeId' => 'test-exec',
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec/executions');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);
        $this->assertEquals('aValue', \json_decode($response['body']['headers'], true)['x-key']);

        /** Execute on cold-started runtime */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec/executions', [], [
            'body' => 'test payload',
            'variables' => [
                'customVariable' => 'mySecret'
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete runtime */
        $response = $this->call(Client::METHOD_DELETE, '/runtimes/test-exec', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Execute on new runtime */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Execution without / at beginning of path */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'path' => 'v1/users',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("200", $response['body']['statusCode']);

        /** Execution with / at beginning of path */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("200", $response['body']['statusCode']);

        /** Execution with different accept headers */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'application/json'
        ], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/json', $response['headers']['content-type']);

        /** accept application/* */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'application/*'
        ], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/json', $response['headers']['content-type']);

        /** accept text/plain, application/json */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'text/plain, application/json'
        ], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/json', $response['headers']['content-type']);

        /** accept application/xml */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'application/xml'
        ], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringStartsWith('multipart/form-data', $response['headers']['content-type']);

        /** accept text/plain */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => 'text/plain'
        ], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringStartsWith('multipart/form-data', $response['headers']['content-type']);

        /** accept */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [
            'accept' => '*/*'
        ], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringStartsWith('multipart/form-data', $response['headers']['content-type']);

        /** Execution with HEAD request */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/executions', [], [
            'source' => $buildPath,
            'method' => 'HEAD',
            'entrypoint' => 'index.php',
            'path' => '/v1/users',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEmpty($response['body']['body']);

        /** Delete runtime */
        $response = $this->call(Client::METHOD_DELETE, '/runtimes/test-exec-coldstart', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    // We also test SSR two Set-cookie here
    public function testSSRLogs(): void
    {
        /** Prepare function */
        $output = '';
        Console::execute('cd /app/tests/resources/sites/astro && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        $params = [
            'runtimeId' => 'test-ssr-build',
            'source' => '/storage/sites/astro/code.tar.gz',
            'destination' => '/storage/builds/test',
            'image' => 'openruntimes/node:v5-22',
            'outputDirectory' => './dist',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "source /usr/local/server/helpers/astro/env.sh && npm install && npm run build && bash /usr/local/server/helpers/astro/bundle.sh"',
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['path']);

        $buildPath = $response['body']['path'];

        $command = 'bash helpers/astro/server.sh';
        $params = [
            'runtimeId' => 'test-ssr-exec',
            'source' => $buildPath,
            'image' => 'openruntimes/node:v5-22',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "source /usr/local/server/helpers/astro/env.sh && ' . $command . '"',
            'path' => '/logs'
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes/test-ssr-exec/executions', [], $params);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);
        $this->assertStringContainsString('<p>OK</p>', $response['body']['body']);

        $setCookieList = \json_decode($response['body']['headers'], true)['set-cookie'];
        $this->assertEquals('astroCookie1=astroValue1; Max-Age=1800; HttpOnly', $setCookieList[0]);
        $this->assertEquals('astroCookie2=astroValue2; Max-Age=1800; HttpOnly', $setCookieList[1]);

        $this->assertNotEmpty($response['body']['logs']);
        $this->assertStringContainsString('Open runtimes log', $response['body']['logs']);
        $this->assertStringContainsString('A developer log', $response['body']['logs']);

        $this->assertNotEmpty($response['body']['errors']);
        $this->assertStringContainsString('Open runtimes error', $response['body']['errors']);

        $response = $this->call(Client::METHOD_POST, '/runtimes/test-ssr-exec/executions', [
            'x-executor-response-format' => '0.10.0' // Last version to report string header values only
        ], $params);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);
        $this->assertEquals('astroCookie2=astroValue2; Max-Age=1800; HttpOnly', \json_decode($response['body']['headers'], true)['set-cookie']);

        /** Delete runtime */
        $response = $this->call(Client::METHOD_DELETE, '/runtimes/test-ssr-exec', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('astroCookie1=astroValue1; Max-Age=1800; HttpOnly', $setCookieList[0]);
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
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh ""'
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $buildPath = $response['body']['path'];

        /** Execute function */
        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-restart-policy/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"',
            'restartPolicy' => 'always'
        ]);
        $this->assertEquals(500, $response['headers']['status-code']);

        \sleep(5);

        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-restart-policy/executions', [], [
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
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
        $response = $this->call(Client::METHOD_DELETE, '/runtimes/test-exec-restart-policy', [], []);
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
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "bash logs_failure.sh"',
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual($size128Kb * 7, \strlen($response['body']['message']));
        $this->assertStringContainsString('First log', $response['body']['message']);
        $this->assertStringContainsString('Last log', $response['body']['message']);

        $output = '';
        Console::execute('cd /app/tests/resources/functions/php-build-logs && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-logs-' . \bin2hex(\random_bytes(4)),
            'source' => '/storage/functions/php-build-logs/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "bash logs_success.sh"',
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);

        $output = '';
        foreach ($response['body']['output'] as $outputItem) {
            $output .= $outputItem['content'];
        }

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual($size128Kb * 7, \strlen($output));
        $this->assertStringContainsString('First log', $output);
        $this->assertStringContainsString('Last log', $output);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-logs-' . \bin2hex(\random_bytes(4)),
            'source' => '/storage/functions/php-build-logs/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "bash logs_failure_large.sh"',
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1000000, \strlen($response['body']['message']));
        $this->assertStringNotContainsString('Last log', $response['body']['message']);
        $this->assertStringContainsString('First log', $response['body']['message']);

        $output = '';
        Console::execute('cd /app/tests/resources/functions/php-build-logs && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build-logs-' . \bin2hex(\random_bytes(4)),
            'source' => '/storage/functions/php-build-logs/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "bash logs_success_large.sh"',
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);

        $output = '';
        foreach ($response['body']['output'] as $outputItem) {
            $output .= $outputItem['content'];
        }

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1000000, \strlen($output));
        $this->assertStringNotContainsString('Last log', $output);
        $this->assertStringContainsString('First log', $output);
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
                'image' => 'openruntimes/node:v5-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-empty-object',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('{}', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v5-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-empty-array',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('[]', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v5-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-timeout',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['headers']['status-code']);
                    $this->assertEquals(500, $response['body']['statusCode']);
                    $this->assertStringContainsString('Execution timed out.', $response['body']['errors']);
                    $this->assertEmpty($response['body']['logs']);
                }
            ],
            [
                'image' => 'openruntimes/node:v5-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-logs',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
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
                'image' => 'openruntimes/node:v5-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-logs',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
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
                'image' => 'openruntimes/node:v5-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-logs',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
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
                'image' => 'openruntimes/node:v5-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-logs',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
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
                'image' => 'openruntimes/node:v5-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-long-coldstart',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
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
                'image' => 'openruntimes/node:v5-21.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-binary-response',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
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
                'image' => 'openruntimes/node:v5-21.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-binary-response',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
                'assertions' => function ($response) {
                    $this->assertEquals(400, $response['headers']['status-code']);
                    $this->assertStringContainsString("JSON response does not allow binaries", $response['body']['message']);
                },
                null, // body,
                'logging' => true,
                'mimeType' => 'application/json'
            ],
            [
                'image' => 'openruntimes/node:v5-21.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-binary-request',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i"',
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
                'image' => 'openruntimes/node:v5-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-specs',
                'version' => 'v5',
                'startCommand' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "bash helpers/server.sh"',
                'buildCommand' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm i && npm run build"',
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
                    $output = '';
                    foreach ($response['body']['output'] as $outputItem) {
                        $output .= $outputItem['content'];
                    }

                    $this->assertStringContainsString("cpus=2.5", $output);
                    $this->assertStringContainsString("memory=1024", $output);
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
    public function testScenarios(string $image, string $entrypoint, string $folder, string $version, string $startCommand, string $buildCommand, callable $assertions, ?callable $body = null, bool $logging = true, string $mimeType = "application/json", float $cpus = 1, int $memory = 512, ?callable $buildAssertions = null): void
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

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
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
        $response = $this->call(Client::METHOD_POST, "/runtimes/scenario-execute-{$folder}-{$runtimeId}/executions", [
            'content-type' => $mimeType,
            'accept' => $mimeType
        ], $params);

        $this->assertStringContainsString($mimeType, $response['headers']['content-type']);

        call_user_func($assertions, $response);

        /** Delete runtime */
        $response = $this->call(Client::METHOD_DELETE, "/runtimes/scenario-execute-{$folder}-{$runtimeId}", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }


    /**
     *
     * @return array<mixed>
     */
    public function provideCustomRuntimes(): array
    {
        return [
            [ 'folder' => 'php', 'image' => 'openruntimes/php:v5-8.1', 'entrypoint' => 'index.php', 'buildCommand' => 'composer install' ],
            [ 'folder' => 'php-mock', 'image' => 'openruntimes/php:v5-8.1', 'entrypoint' => 'index.php', 'buildCommand' => 'composer install' ],
            [ 'folder' => 'node', 'image' => 'openruntimes/node:v5-18.0', 'entrypoint' => 'index.js', 'buildCommand' => 'npm i'],
            // [ 'folder' => 'deno', 'image' => 'openruntimes/deno:v5-1.24', 'entrypoint' => 'index.ts', 'buildCommand' => 'deno cache index.ts', 'startCommand' => 'denon start' ],
            [ 'folder' => 'python', 'image' => 'openruntimes/python:v5-3.10', 'entrypoint' => 'index.py', 'buildCommand' => 'pip install -r requirements.txt'],
            [ 'folder' => 'ruby', 'image' => 'openruntimes/ruby:v5-3.1', 'entrypoint' => 'index.rb', 'buildCommand' => ''],
            [ 'folder' => 'cpp', 'image' => 'openruntimes/cpp:v5-17', 'entrypoint' => 'index.cc', 'buildCommand' => ''],
            [ 'folder' => 'dart', 'image' => 'openruntimes/dart:v5-2.18', 'entrypoint' => 'lib/index.dart', 'buildCommand' => 'dart pub get'],
            [ 'folder' => 'dotnet', 'image' => 'openruntimes/dotnet:v5-6.0', 'entrypoint' => 'Index.cs', 'buildCommand' => ''],
            // Swift, Kotlin, Java missing on purpose
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
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "' . $buildCommand . '"',
            'remove' => true
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        if ($response['headers']['status-code'] !== 201) {
            \var_dump($response);
        }
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsString($response['body']['path']);

        $path = $response['body']['path'];

        // Execute function
        $response = $this->call(Client::METHOD_POST, "/runtimes/custom-execute-{$folder}-{$runtimeId}/executions", [], [
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
        $this->assertEquals(13, $response['todo']['userId']);

        /** Delete runtime */
        $response = $this->call(Client::METHOD_DELETE, "/runtimes/custom-execute-{$folder}-{$runtimeId}", [], []);
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
            'image' => 'openruntimes/php:v5-8.1',
            'command' => 'unzip /tmp/code.tar.gz -d /mnt/code && bash helpers/build.sh "composer install"',
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty(201, $response['body']['path']);

        $buildPath = $response['body']['path'];

        /** Test executions */
        $command = 'php src/server.php';
        $params = [
            'runtimeId' => 'test-exec-zip-' . $runtimeId,
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
        ];

        $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->call(Client::METHOD_POST, '/runtimes/test-exec-zip-' . $runtimeId .'/executions');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);

        /** Delete runtime */
        $response = $this->call(Client::METHOD_DELETE, '/runtimes/test-exec-zip-' . $runtimeId, [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testCommands(): void
    {
        $runtime = $this->call(Client::METHOD_POST, '/runtimes', [], [
            'runtimeId' => 'test-commands',
            'remove' => false,
            'image' => 'openruntimes/php:v5-8.1',
            'entrypoint' => 'tail -f /dev/null',
        ]);
        $this->assertEquals(201, $runtime['headers']['status-code']);

        $command = $this->call(Client::METHOD_POST, '/runtimes/test-commands/commands', [], [
            'command' => 'echo "Hello, World!"'
        ]);
        $this->assertEquals(200, $command['headers']['status-code']);
        $this->assertStringContainsString('Hello, World!', $command['body']['output']); // not equals, because echo adds a newline

        $command = $this->call(Client::METHOD_POST, '/runtimes/test-commands/commands', [], [
            'command' => 'sleep 5 && echo "Ok"',
            'timeout' => 1
        ]);
        $this->assertEquals(500, $command['headers']['status-code']);
        $this->assertStringContainsString('Operation timed out', $command['body']['message']);

        $command = $this->call(Client::METHOD_POST, '/runtimes/test-commands/commands', [], [
            'command' => 'exit 1'
        ]);
        $this->assertEquals(500, $command['headers']['status-code']);
        $this->assertStringContainsString('Failed to execute command', $command['body']['message']);

        $response = $this->call(Client::METHOD_DELETE, "/runtimes/test-commands", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->call(Client::METHOD_DELETE, "/runtimes/test-commands", [], []);
        $this->assertEquals(404, $response['headers']['status-code']);

        $command = $this->call(Client::METHOD_POST, '/runtimes/test-commands/commands', [], [
            'command' => 'echo 123'
        ]);
        $this->assertEquals(404, $command['headers']['status-code']);
    }

    public function testLogStreamPersistent(): void
    {
        $output = '';
        Console::execute('cd /app/tests/resources/functions/node && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output);

        $runtimeEnd = 0;
        $realtimeEnd = 0;

        Co\run(function () use (&$runtimeEnd, &$realtimeEnd) {
            Co::join([
                /** Watch logs */
                Co\go(function () use (&$realtimeEnd) {
                    $this->call(Client::METHOD_GET, '/runtimes/test-log-stream-persistent/logs', [], [], true);

                    $realtimeEnd = \microtime(true);
                }),
                /** Start runtime */
                Co\go(function () use (&$runtimeEnd) {
                    $params = [
                        'runtimeId' => 'test-log-stream-persistent',
                        'source' => '/storage/functions/node/code.tar.gz',
                        'destination' => '/storage/builds/test-logs',
                        'entrypoint' => 'index.js',
                        'image' => 'openruntimes/node:v5-18.0',
                        'workdir' => '/usr/code',
                        'remove' => false,
                        'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "npm install && npm run build"'
                    ];

                    $response = $this->call(Client::METHOD_POST, '/runtimes', [], $params);
                    $this->assertEquals(201, $response['headers']['status-code']);

                    $runtimeEnd = \microtime(true);
                }),
            ]);
        });

        $diff = \abs($runtimeEnd - $realtimeEnd);
        $this->assertLessThanOrEqual(1, $diff);

        $response = $this->call(Client::METHOD_DELETE, "/runtimes/test-log-stream-persistent", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }
}
