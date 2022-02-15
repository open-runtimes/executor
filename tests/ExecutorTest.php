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


    public function testGetRuntimes(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/runtimes', [], []);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, count($response['body']));
    }
} 