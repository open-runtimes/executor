<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Console;

/**
 * End to end coverage for the streamed response format (x-executor-response-format 0.12.0).
 *
 * Asserts against the raw wire, not a decoded body, because the point of the format is the framing:
 * that parts arrive length prefixed, that statusCode and headers land before the body, and that a
 * large body is framed as several runs rather than one document.
 */
class StreamedResponseTest extends TestCase
{
    protected string $endpoint = 'http://executor/v1';

    protected string $key = 'executor-secret-key';

    protected Client $client;

    protected function setUp(): void
    {
        $this->client = new Client($this->endpoint, [
            'content-type' => 'application/json',
        ]);
        $this->client->setKey($this->key);
    }

    /**
     * Reads the 0.12.0 envelope. Mirrors the reader on the caller's side: content is located by its
     * length prefix and never by scanning for the boundary.
     *
     * @return array{parts: array<string, string>, runs: array<string, int>, order: array<int, string>}
     */
    private function parse(string $boundary, string $wire): array
    {
        $parts = [];
        $runs = [];
        $order = [];
        $offset = 0;
        $delimiter = '--' . $boundary;

        while (true) {
            $this->assertSame($delimiter, \substr($wire, $offset, \strlen($delimiter)), 'expected delimiter at ' . $offset);
            $offset += \strlen($delimiter);

            if (\substr($wire, $offset, 2) === '--') {
                break; // closing delimiter
            }

            $this->assertSame("\r\n", \substr($wire, $offset, 2));
            $offset += 2;

            $end = \strpos($wire, "\r\n\r\n", $offset);
            $this->assertNotFalse($end, 'unterminated part headers');
            $headers = \substr($wire, $offset, $end - $offset);
            $offset = $end + 4;

            $this->assertMatchesRegularExpression('/Content-Transfer-Encoding:\s*chunked/i', $headers, 'part is not chunked: ' . $headers);
            $this->assertSame(1, \preg_match('/name="([^"]+)"/', $headers, $m), 'part has no name: ' . $headers);
            $name = $m[1];

            $order[] = $name;
            $parts[$name] = '';
            $runs[$name] = 0;

            while (true) {
                $eol = \strpos($wire, "\r\n", $offset);
                $this->assertNotFalse($eol, 'unterminated chunk size');
                $size = \substr($wire, $offset, $eol - $offset);
                $this->assertMatchesRegularExpression('/^[0-9a-fA-F]+$/', $size, 'bad chunk size "' . $size . '"');
                $offset = $eol + 2;

                $length = \intval($size, 16);

                if ($length === 0) {
                    $this->assertSame("\r\n", \substr($wire, $offset, 2), 'part terminator must be followed by CRLF');
                    $offset += 2;
                    break;
                }

                $parts[$name] .= \substr($wire, $offset, $length);
                $runs[$name]++;
                $offset += $length;

                $this->assertSame("\r\n", \substr($wire, $offset, 2), 'chunk must be followed by CRLF');
                $offset += 2;
            }
        }

        return ['parts' => $parts, 'runs' => $runs, 'order' => $order];
    }

    private function build(string $folder, string $image, string $entrypoint, string $buildCommand, string $runtimeId): string
    {
        $output = '';
        $stderr = '';
        Console::execute('cd /app/tests/resources/functions/' . $folder . ' && tar --exclude code.tar.gz -czf code.tar.gz .', '', $output, $stderr);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], [
            'runtimeId' => $runtimeId . '-build',
            'source' => '/storage/functions/' . $folder . '/code.tar.gz',
            'destination' => '/storage/builds/' . $runtimeId,
            'entrypoint' => $entrypoint,
            'image' => $image,
            'command' => 'tar -zxf /tmp/code.tar.gz -C /mnt/code && bash helpers/build.sh "' . $buildCommand . '"',
            'remove' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code'], 'build failed: ' . \json_encode($response));

        return $response['body']['path'];
    }

    /**
     * @return array{wire: string, headers: array<string, mixed>, chunks: int}
     */
    private function executeStreamed(string $runtimeId, array $params, string $format = '0.12.0'): array
    {
        $wire = '';
        $chunks = 0;

        $response = $this->client->call(
            Client::METHOD_POST,
            '/runtimes/' . $runtimeId . '/executions',
            [
                'x-executor-response-format' => $format,
                'accept' => 'multipart/form-data',
            ],
            $params,
            false,
            function (string $data) use (&$wire, &$chunks): void {
                $wire .= $data;
                $chunks++;
            }
        );

        return ['wire' => $wire, 'headers' => $response['headers'], 'chunks' => $chunks];
    }

    public function testStreamedEnvelopeIsFramedAndOrdered(): void
    {
        $runtimeId = 'stream-' . \bin2hex(\random_bytes(4));
        $buildPath = $this->build('php-mock', 'openruntimes/php:v5-8.1', 'index.php', 'composer install', $runtimeId);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], [
            'runtimeId' => $runtimeId,
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "php src/server.php"',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $result = $this->executeStreamed($runtimeId, []);

        // The executor must announce the format, otherwise a caller cannot tell it apart from a
        // buffered document and will not attempt an incremental read.
        $this->assertEquals('0.12.0', $result['headers']['x-executor-response-format'] ?? null, 'format not echoed: ' . \json_encode($result['headers']));

        $contentType = $result['headers']['content-type'] ?? '';
        $this->assertStringStartsWith('multipart/form-data', (string) $contentType);
        $this->assertSame(1, \preg_match('/boundary=(.+)$/', (string) $contentType, $m), 'no boundary in ' . $contentType);
        $boundary = \trim($m[1], '"');

        $parsed = $this->parse($boundary, $result['wire']);

        $this->assertSame(
            ['statusCode', 'headers', 'body', 'logs', 'errors', 'duration', 'startTime'],
            $parsed['order'],
            'unexpected part order'
        );

        // statusCode and headers must precede the body: the proxy cannot set either once content
        // has started going out to the visitor.
        $this->assertSame(0, \array_search('statusCode', $parsed['order'], true));
        $this->assertSame(1, \array_search('headers', $parsed['order'], true));

        $this->assertSame('200', $parsed['parts']['statusCode']);

        $headers = \json_decode($parsed['parts']['headers'], true);
        $this->assertIsArray($headers);
        $this->assertArrayHasKey('content-type', $headers);

        $body = \json_decode($parsed['parts']['body'], true);
        $this->assertIsArray($body);
        $this->assertTrue($body['isTest']);
        $this->assertSame('Hello Open Runtimes 👋', $body['message']);

        $this->assertStringContainsString('Sample Log', $parsed['parts']['logs']);
        $this->assertGreaterThan(0, (float) $parsed['parts']['duration']);
        $this->assertGreaterThan(0, (float) $parsed['parts']['startTime']);

        $this->client->call(Client::METHOD_DELETE, '/runtimes/' . $runtimeId, [], []);
    }

    public function testLargeBodyIsFramedAsSeveralRuns(): void
    {
        $runtimeId = 'stream-large-' . \bin2hex(\random_bytes(4));
        $buildPath = $this->build('node-large-response', 'openruntimes/node:v5-18.0', 'index.js', 'npm install', $runtimeId);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], [
            'runtimeId' => $runtimeId,
            'source' => $buildPath,
            'entrypoint' => 'index.js',
            'image' => 'openruntimes/node:v5-18.0',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "node src/server.js"',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $result = $this->executeStreamed($runtimeId, [
            'headers' => ['x-response-size' => '1048576'],
        ]);

        $this->assertEquals('0.12.0', $result['headers']['x-executor-response-format'] ?? null);

        \preg_match('/boundary=(.+)$/', (string) ($result['headers']['content-type'] ?? ''), $m);
        $parsed = $this->parse(\trim($m[1] ?? '', '"'), $result['wire']);

        $this->assertGreaterThanOrEqual(1048576, \strlen($parsed['parts']['body']));
        $this->assertSame('200', $parsed['parts']['statusCode']);

        // The whole point: a body this size is forwarded as it arrives, so it is framed as many
        // runs. One run would mean the executor buffered it after all.
        $this->assertGreaterThan(
            1,
            $parsed['runs']['body'],
            'body was framed as a single run, so it was buffered rather than streamed'
        );

        // And it arrived over several socket reads on the caller's side too.
        $this->assertGreaterThan(1, $result['chunks'], 'response arrived in one read');

        $this->client->call(Client::METHOD_DELETE, '/runtimes/' . $runtimeId, [], []);
    }

    public function testOlderFormatStillGetsBufferedDocument(): void
    {
        $runtimeId = 'stream-compat-' . \bin2hex(\random_bytes(4));
        $buildPath = $this->build('php-mock', 'openruntimes/php:v5-8.1', 'index.php', 'composer install', $runtimeId);

        $response = $this->client->call(Client::METHOD_POST, '/runtimes', [], [
            'runtimeId' => $runtimeId,
            'source' => $buildPath,
            'entrypoint' => 'index.php',
            'image' => 'openruntimes/php:v5-8.1',
            'runtimeEntrypoint' => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "php src/server.php"',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $result = $this->executeStreamed($runtimeId, [], '0.11.0');

        // No echo, so a caller keeps buffering, and no part carries a chunked encoding.
        $this->assertArrayNotHasKey('x-executor-response-format', $result['headers']);
        $this->assertStringNotContainsStringIgnoringCase('Content-Transfer-Encoding: chunked', $result['wire']);
        $this->assertStringContainsString('Hello Open Runtimes', $result['wire']);

        $this->client->call(Client::METHOD_DELETE, '/runtimes/' . $runtimeId, [], []);
    }
}
