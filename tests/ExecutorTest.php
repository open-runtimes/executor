<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

final class ExecutorTest extends TestCase
{

    /**
     * @var Client
     */
    protected $client = null;

    /**
     * @var string
     */
    protected $endpoint = 'http://openruntimes-executor/v1';

    protected function setUp(): void
    {
        $this->client = new Client();

        $this->client
            ->setEndpoint($this->endpoint)
            ->addHeader('Content-Type', 'application/json')
            ->setKey('a-random-secret');
    }

    protected function tearDown(): void
    {
        $this->client = null;
    }

    public function testUnauthorized()
    {
        $this->client->setKey('');
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('Missing executor key', $response['body']['message']);
    }

    public function testUnknownRoute()
    {
        $response = $this->client->call(Client::METHOD_GET, '/unknown', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Not Found', $response['body']['message']);
    }

    public function testGetRuntimes(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, count($response['body']));
    }

    public function testGetRuntime(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/runtimes/id', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);
    }

    public function testDeleteRuntime()
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);
    }

    public function testCreateRuntime(): void
    {
        $params = [
            'runtimeId' => "test",
            'source' => '/storage/functions/php-fn.tar.gz',
            'destination' => '/storage/builds/test',
            'vars' => [],
            'runtime' => 'php-8.0',
            'baseImage' => 'php-runtime:8.0',
        ];
        
        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('ready', $response['body']['status']);
        $this->assertEquals('Build Successful!', $response['body']['stdout']);

        /** List runtimes */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, count($response['body']));
        $this->assertEquals('runtime-test', $response['body'][0]['name']);

        /** Get runtime */
        $response = $this->client->call(Client::METHOD_GET, '/runtimes/test', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('runtime-test', $response['body']['name']);

        /** Delete runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        
        /** Delete non existent runtime */
        $response = $this->client->call(Client::METHOD_DELETE, '/runtimes/test', [], []);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('Runtime not found', $response['body']['message']);
    }

    public function testCreateExecution(): void 
    {
        $params = [
            'runtimeId' => "test",
            'source' => '/storage/functions/php-fn.tar.gz',
            'destination' => '/storage/builds/test',
            'vars' => [],
            'runtime' => 'php-8.0',
            'baseImage' => 'php-runtime:8.0',
        ];
        
        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], $params);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('ready', $response['body']['status']);
        $this->assertEquals('Build Successful!', $response['body']['stdout']);
        $outputPath = $response['body']['outputPath'];
        $this->assertNotEmpty($outputPath);

        /** Create Execution */
        $params = [
            'runtimeId' => 'test',
            'path' => $outputPath,
            'vars' => [],
            'data' => '',
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 15,
            'baseImage' => 'php-runtime:8.0',
        ];

        $response = $this->client->call(Client::METHOD_POST, '/execution', [], $params);
        var_dump($response);
        $this->assertEquals(201, $response['headers']['status-code']);
    }
} 