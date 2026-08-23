<?php

namespace OpenRuntimes\Executor;

/**
 * Incremental writer for the streaming multipart response format (x-executor-response-format
 * 0.12.0 and above).
 *
 * BodyMultipart serialises a finished document, which means the whole response has to exist in
 * memory before a single byte reaches the client. This writes the envelope as parts are produced,
 * so a response can leave the executor while the runtime is still generating it.
 *
 * Part content is length prefixed, in the same shape HTTP uses for chunked transfer encoding:
 *
 *     --BOUNDARY\r\n
 *     Content-Disposition: form-data; name="body"\r\n
 *     Content-Transfer-Encoding: chunked\r\n
 *     \r\n
 *     <hex length>\r\n<content>\r\n     (repeated for each run of content)
 *     0\r\n\r\n                         (terminates the part)
 *     --BOUNDARY--                      (after the last part)
 *
 * The length prefix is what makes an incremental read tractable on the other side: content is
 * never scanned for the boundary, so content that happens to contain the boundary string cannot
 * split the envelope, and the reader needs no lookahead between reads.
 */
class BodyMultipartStream
{
    private bool $ended = false;
    private bool $inPart = false;

    /**
     * @param string $boundary
     * @param callable(string): void $write Receives raw bytes to put on the wire.
     */
    public function __construct(
        private readonly string $boundary,
        private readonly mixed $write,
    ) {
    }

    public function exportHeader(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    /**
     * Open a part. Content follows via writeContent(), and the part is closed by endPart().
     */
    public function startPart(string $name): void
    {
        if ($this->ended || $this->inPart) {
            return;
        }

        $this->inPart = true;

        ($this->write)(
            '--' . $this->boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n"
            . 'Content-Transfer-Encoding: chunked' . "\r\n\r\n"
        );
    }

    /**
     * Append a run of content to the open part.
     */
    public function writeContent(string $content): void
    {
        // A zero length run is the part terminator, so an empty write has to be dropped rather
        // than forwarded. Callers stream whatever curl hands them, and curl can hand over nothing.
        if ($this->ended || !$this->inPart || $content === '') {
            return;
        }

        ($this->write)(\dechex(\strlen($content)) . "\r\n" . $content . "\r\n");
    }

    public function endPart(): void
    {
        if ($this->ended || !$this->inPart) {
            return;
        }

        $this->inPart = false;

        ($this->write)("0\r\n\r\n");
    }

    /**
     * Write a part whose content is already known in full.
     *
     * @param mixed $value Scalars are stringified; arrays are JSON encoded, matching BodyMultipart.
     */
    public function part(string $name, mixed $value): void
    {
        $this->startPart($name);
        $this->writeContent(\is_array($value) ? (\json_encode($value) ?: '') : \strval($value));
        $this->endPart();
    }

    /**
     * Close the envelope. Any part still open is terminated first.
     */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->endPart();
        $this->ended = true;

        ($this->write)('--' . $this->boundary . '--');
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }
}
