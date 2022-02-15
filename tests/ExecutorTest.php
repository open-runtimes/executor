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
        ;
    }

    protected function tearDown(): void
    {
        $this->client = null;
    }


    public function testRuntimeExample(): void
    {
        $response = $this->client->call([
            'id' => 1
        ]);

        self::assertEquals(200, $response['code']);
        self::assertEquals('Hello Open Runtimes 👋', $response['body']['message']);
        self::assertEquals('1', $response['body']['todo']['userId']);
        self::assertEquals('1', $response['body']['todo']['id']);
        self::assertEquals('delectus aut autem', $response['body']['todo']['title']);
        self::assertEquals(false, $response['body']['todo']['completed']);

        $response = $this->call([
            'payload' => '{"id":"2"}'
        ]);

        self::assertEquals(200, $response['code']);
        self::assertEquals('Hello Open Runtimes 👋', $response['body']['message']);
        self::assertEquals('1', $response['body']['todo']['userId']);
        self::assertEquals('2', $response['body']['todo']['id']);
        self::assertEquals('quis ut nam facilis et officia qui', $response['body']['todo']['title']);
        self::assertEquals(false, $response['body']['todo']['completed']);
    }
} 