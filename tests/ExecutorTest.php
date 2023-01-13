<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Utopia\CLI\Console;

// TODO: @Meldiron Write more tests (validators mainly)
// TODO: @Meldiron Health API tests

// TODO: @Meldiron tests for length of logs
// TODO: @Meldiron tests for both V2 and V3

final class ExecutorTest extends TestCase
{
    protected Client $client;

    protected string $key;

    /**
     * @var string
     */
    protected $endpoint = 'http://exc1/v1';

    protected function setUp(): void
    {
        $this->client = new Client();

        $this->key = 'executor-secret-key';

        $this->client
            ->setEndpoint($this->endpoint)
            ->addHeader('Content-Type', 'application/json')
            ->setKey($this->key);
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
        $stdout = '';
        $stderr = '';
        Console::execute('cd /app/tests/resources/functions/php && tar --warning=no-file-changed --exclude code.tar.gz -czf code.tar.gz .', '', $stdout, $stderr);

        $this->assertEquals('', $stderr);

        /** Build runtime */
        $params = [
            'runtimeId' => 'test-build',
            'source' => '/storage/functions/php/code.tar.gz',
            'destination' => '/storage/builds/test',
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.1',
            'workdir' => '/usr/code',
            'commands' => [
                'sh', '-c',
                'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                cd /usr/local/src/ && ./build.sh'
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsString($response['body']['path']);
        $this->assertIsString($response['body']['errors']);
        $this->assertIsString($response['body']['logs']);
        $this->assertIsFloat($response['body']['duration']);
        $this->assertIsFloat($response['body']['startTime']);
        $this->assertIsInt($response['body']['size']);

        $buildPath = $response['body']['path'];

        /** List runtimes */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, count($response['body']));
        $this->assertEquals('test-build', $response['body'][0]['name']);

        /** Get runtime */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes/test-build', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('test-build', $response['body']['name']);

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

        return [ 'path' => $buildPath ];
    }

    /**
     * @depends testBuild
     *
     * @param array<string,mixed> $data
     */
    public function testExecute(array $data): void
    {
        $params = [
            'runtimeId' => 'test-exec',
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.1',
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/execution');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(200, $response['body']['statusCode']);

        /** Execute on cold-started runtime */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec/execution', [], [
            'payload' => 'test payload',
            'variables' => [
                'customVariable' => 'mySecret'
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);

        /** Execute on new runtime */
        $response = $this->client->call(Client::METHOD_POST, '/runtimes/test-exec-coldstart/execution', [], [
            'source' => $data['path'],
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v2-8.1',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test-exec-coldstart', [], []);
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
                'folder' => 'node-empty-object',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('{}', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v2-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-empty-array',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('[]', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertEmpty($response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/node:v2-18.0',
                'entrypoint' => 'index.js',
                'folder' => 'node-stderr',
                'assertions' => function ($response) {
                    $this->assertEquals(200, $response['body']['statusCode']);
                    $this->assertEquals('{"ok":true}', $response['body']['body']);
                    $this->assertEmpty($response['body']['logs']);
                    $this->assertStringContainsString('Error log', $response['body']['errors']);
                }
            ],
            [
                'image' => 'openruntimes/php:v2-8.1',
                'entrypoint' => 'index.php',
                'folder' => 'php-timeout',
                'assertions' => function ($response) {
                    $this->assertEquals(500, $response['headers']['status-code']);
                    $this->assertEquals(500, $response['body']['code']);
                    $this->assertStringContainsString('Operation timed out', $response['body']['message']);
                }
            ]
            // TODO: @Meldiron Add failed execution test
        ];
    }

    /**
     * @param string $image
     * @param string $entrypoint
     * @param string $folder
     * @param callable $assertions
     *
     * @dataProvider provideScenarios
     */
    public function testScenarios(string $image, string $entrypoint, string $folder, callable $assertions): void
    {
        /** Prepare deployment */
        $stdout = '';
        $stderr = '';
        Console::execute("cd /app/tests/resources/functions/{$folder} && tar --warning=no-file-changed --exclude code.tar.gz -czf code.tar.gz .", '', $stdout, $stderr);

        $this->assertEquals('', $stderr);

        /** Build runtime */
        $params = [
            'runtimeId' => "scenario-build-{$folder}",
            'source' => "/storage/functions/{$folder}/code.tar.gz",
            'destination' => '/storage/builds/test',
            'entrypoint' => $entrypoint,
            'image' => $image,
            'workdir' => '/usr/code',
            'commands' => [
                'sh', '-c',
                'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                cd /usr/local/src/ && ./build.sh'
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);

        $path = $response['body']['path'];

        /** Execute function */
        $response = $this->client->call(Client::METHOD_POST, "/runtimes/scenario-execute-{$folder}/execution", [], [
            'source' => $path,
            'entrypoint' => $entrypoint,
            'image' => $image,
        ]);

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
            [ 'folder' => 'php', 'image' => 'openruntimes/php:v2-8.1', 'entrypoint' => 'index.php' ],
            [ 'folder' => 'node', 'image' => 'openruntimes/node:v2-18.0', 'entrypoint' => 'index.js' ],
            [ 'folder' => 'deno', 'image' => 'openruntimes/deno:v2-1.24', 'entrypoint' => 'index.ts' ],
            [ 'folder' => 'python', 'image' => 'openruntimes/python:v2-3.9', 'entrypoint' => 'index.py' ],
            [ 'folder' => 'ruby', 'image' => 'openruntimes/ruby:v2-3.1', 'entrypoint' => 'index.rb' ],
            [ 'folder' => 'cpp', 'image' => 'openruntimes/cpp:v2-17', 'entrypoint' => 'index.cc' ],
            [ 'folder' => 'dart', 'image' => 'openruntimes/dart:v2-2.17', 'entrypoint' => 'lib/index.dart' ],
            [ 'folder' => 'dotnet', 'image' => 'openruntimes/dotnet:v2-6.0', 'entrypoint' => 'Index.cs' ],
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
    public function testCustomRuntimes(string $folder, string $image, string $entrypoint): void
    {
        // Prepare tar.gz files
        $stdout = '';
        $stderr = '';
        Console::execute("cd /app/tests/resources/functions/{$folder} && tar --warning=no-file-changed --exclude code.tar.gz -czf code.tar.gz .", '', $stdout, $stderr);

        $this->assertEquals('', $stderr);

        // Build deployment
        $params = [
            'runtimeId' => "custom-build-{$folder}",
            'source' => "/storage/functions/{$folder}/code.tar.gz",
            'destination' => '/storage/builds/test',
            'entrypoint' => $entrypoint,
            'image' => $image,
            'workdir' => '/usr/code',
            'timeout' => 60,
            'commands' => [
                'sh', '-c',
                'tar -zxf /tmp/code.tar.gz -C /usr/code && \
                cd /usr/local/src/ && ./build.sh'
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsString($response['body']['path']);

        $path = $response['body']['path'];

        // Execute function
        $response = $this->client->call(Client::METHOD_POST, "/runtimes/custom-execute-{$folder}/execution", [
            'content-type' => 'application/json',
        ], [
            'source' => $path,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'timeout' => 60,
            'variables' => [
                'test-variable' => 'Variable secret'
            ],
            'payload' => \json_encode([
                'id' => '2'
            ])
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $body = $response['body'];
        $this->assertEquals(200, $body['statusCode']);
        $this->assertEmpty($body['errors']); // TODO: @Meldiron Proper assertion
        $this->assertStringContainsString('Sample Log', $body['logs']);
        $this->assertIsString($body['body']);
        $this->assertNotEmpty($body['body']);
        $response = \json_decode($body['body'], true);
        $this->assertEquals(true, $response['isTest']);
        $this->assertEquals('Hello Open Runtimes 👋', $response['message']);
        $this->assertEquals('Variable secret', $response['variable']);
        $this->assertEquals(1, $response['todo']['userId']);

        // TODO: @Meldiron Add tests for headers

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, "/runtimes/custom-execute-{$folder}", [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
    }
}
