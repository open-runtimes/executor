<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use OpenRuntimes\Executor\BodyMultipartStream;
use PHPUnit\Framework\TestCase;

final class BodyMultipartStreamTest extends TestCase
{
    private string $wire = '';

    private function writer(string $boundary = 'BOUNDARY'): BodyMultipartStream
    {
        $this->wire = '';

        return new BodyMultipartStream($boundary, function (string $bytes): void {
            $this->wire .= $bytes;
        });
    }

    public function testPartIsLengthPrefixedAndChunkMarked(): void
    {
        $stream = $this->writer();
        $stream->part('statusCode', 200);

        $this->assertSame(
            "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"statusCode\"\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "3\r\n200\r\n"
            . "0\r\n\r\n",
            $this->wire
        );
    }

    public function testLengthPrefixIsHexadecimal(): void
    {
        $stream = $this->writer();
        $stream->startPart('body');
        $stream->writeContent(\str_repeat('x', 255));
        $stream->endPart();

        $this->assertStringContainsString("\r\nff\r\n" . \str_repeat('x', 255) . "\r\n", $this->wire);
    }

    public function testArrayPartIsJsonEncoded(): void
    {
        $stream = $this->writer();
        $stream->part('headers', ['content-type' => 'text/html']);

        $this->assertStringContainsString('{"content-type":"text\/html"}', $this->wire);
    }

    public function testEachContentRunGetsItsOwnPrefix(): void
    {
        $stream = $this->writer();
        $stream->startPart('body');
        $stream->writeContent('abc');
        $stream->writeContent('de');
        $stream->endPart();

        $this->assertStringContainsString("3\r\nabc\r\n2\r\nde\r\n0\r\n\r\n", $this->wire);
    }

    public function testEmptyContentRunIsDroppedRatherThanTerminatingThePart(): void
    {
        $stream = $this->writer();
        $stream->startPart('body');
        $stream->writeContent('');
        $stream->writeContent('after');
        $stream->endPart();

        // A zero length run would read as the part terminator, orphaning everything after it.
        $this->assertStringNotContainsString("0\r\n\r\nafter", $this->wire);
        $this->assertStringContainsString("5\r\nafter\r\n0\r\n\r\n", $this->wire);
    }

    public function testEmptyPartStillFramesSoTheKeyExists(): void
    {
        $stream = $this->writer();
        $stream->part('errors', '');

        $this->assertStringContainsString('name="errors"', $this->wire);
        $this->assertStringEndsWith("0\r\n\r\n", $this->wire);
    }

    public function testEndClosesEnvelopeWithTerminalDelimiter(): void
    {
        $stream = $this->writer();
        $stream->part('logs', 'hello');
        $stream->end();

        $this->assertStringEndsWith('--BOUNDARY--', $this->wire);
        $this->assertTrue($stream->isEnded());
    }

    public function testEndTerminatesAnOpenPartFirst(): void
    {
        $stream = $this->writer();
        $stream->startPart('body');
        $stream->writeContent('partial');
        $stream->end();

        $this->assertStringEndsWith("7\r\npartial\r\n0\r\n\r\n--BOUNDARY--", $this->wire);
    }

    public function testWritesAfterEndAreIgnored(): void
    {
        $stream = $this->writer();
        $stream->part('logs', 'x');
        $stream->end();
        $after = $this->wire;

        $stream->part('errors', 'ignored');
        $stream->end();

        $this->assertSame($after, $this->wire);
    }

    public function testNestedStartPartIsIgnored(): void
    {
        $stream = $this->writer();
        $stream->startPart('body');
        $stream->startPart('other');

        $this->assertStringNotContainsString('name="other"', $this->wire);
    }

    public function testExportHeaderCarriesBoundary(): void
    {
        $stream = $this->writer('abc123');

        $this->assertSame('multipart/form-data; boundary=abc123', $stream->exportHeader());
    }

    public function testContentContainingBoundaryDoesNotSplitEnvelope(): void
    {
        $stream = $this->writer();
        $payload = "--BOUNDARY--\r\n0\r\n\r\nnot a terminator";

        $stream->startPart('body');
        $stream->writeContent($payload);
        $stream->endPart();
        $stream->end();

        // Content is never scanned for the boundary, so the length prefix is the only framing.
        $this->assertStringContainsString(\dechex(\strlen($payload)) . "\r\n" . $payload . "\r\n", $this->wire);
    }

    public function testBinaryContentIsLengthedInBytes(): void
    {
        $stream = $this->writer();
        $binary = \random_bytes(1024);

        $stream->startPart('body');
        $stream->writeContent($binary);
        $stream->endPart();

        $this->assertStringContainsString("400\r\n" . $binary . "\r\n", $this->wire);
    }
}
