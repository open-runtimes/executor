<?php

namespace Tests;

use PHPUnit\Framework\TestCase;


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class ExecutorTest extends TestCase
{
    private function call($body) {
        $ch = \curl_init();

        $optArray = array(
            CURLOPT_URL => 'http://172.17.0.1:3000', // Docker loopback address to host machine's localhost
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \json_encode($body),
            CURLOPT_HEADEROPT => \CURLHEADER_UNIFIED,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Internal-Challenge: ' . \getenv('INTERNAL_RUNTIME_KEY'))
        );

        \curl_setopt_array($ch, $optArray);

        $result = curl_exec($ch);
        $response = curl_getinfo($ch, \CURLINFO_HTTP_CODE);

        \curl_close($ch);

        $resultJson = \json_decode($result, true);

        return [
            'code' => $response,
            'body' => $resultJson
        ];
    }

    public function testRuntimeExample(): void
    {
        $response = $this->call([
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