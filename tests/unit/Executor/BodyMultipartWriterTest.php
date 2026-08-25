<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use OpenRuntimes\Executor\BodyMultipartWriter;
use PHPUnit\Framework\TestCase;

final class BodyMultipartWriterTest extends TestCase
{
    private string $wire = '';

    private function writer(string $boundary = 'BOUNDARY'): BodyMultipartWriter
    {
        $this->wire = '';

        return new BodyMultipartWriter($boundary, function (string $bytes): void {
            $this->wire .= $bytes;
        });
    }

    public function testPartIsLengthPrefixedAndChunkMarked(): void
    {
        $stream = $this->writer();
        $stream->writePart('statusCode', 200);

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
        $stream->writePart('headers', ['content-type' => 'text/html']);

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
        $this->assertSame(
            "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"body\"\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "5\r\nafter\r\n"
            . "0\r\n\r\n",
            $this->wire
        );
    }

    public function testEmptyPartStillFramesSoTheKeyExists(): void
    {
        $stream = $this->writer();
        $stream->writePart('errors', '');

        $this->assertSame(
            "--BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"errors\"\r\n"
            . "Content-Transfer-Encoding: chunked\r\n\r\n"
            . "0\r\n\r\n",
            $this->wire
        );
    }

    public function testEndClosesEnvelopeWithTerminalDelimiter(): void
    {
        $stream = $this->writer();
        $stream->writePart('logs', 'hello');
        $stream->end();

        $this->assertStringEndsWith('--BOUNDARY--', $this->wire);
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
        $stream->writePart('logs', 'x');
        $stream->end();

        $after = $this->wire;

        $stream->writePart('errors', 'ignored');
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

    public function testAbandonedEnvelopeCarriesNoClosingDelimiter(): void
    {
        $stream = $this->writer();
        $stream->startPart('body');
        $stream->writeContent('half a page');
        unset($stream);

        // An execution that dies here never calls end(). The caller reads a missing closing
        // delimiter as a failed execution, so nothing may close the envelope on its behalf.
        $this->assertStringNotContainsString('--BOUNDARY--', $this->wire);
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
