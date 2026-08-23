<?php

namespace OpenRuntimes\Executor;

/**
 * Writes the multipart response format of x-executor-response-format 0.12.0, where BodyMultipart
 * serialises a finished document. Part content is length prefixed as
 * `<hex length>\r\n<content>\r\n`, closed by `0\r\n\r\n`, so the reader locates content by its
 * length and never scans for the boundary.
 */
class BodyMultipartStream
{
    private bool $ended = false;

    private bool $inPart = false;

    /**
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
     * @param mixed $value Scalars are stringified; arrays are JSON encoded, matching BodyMultipart.
     */
    public function part(string $name, mixed $value): void
    {
        if (\is_array($value)) {
            $value = \json_encode($value);

            // Nothing has been written yet, so the failure can still surface as an error.
            if ($value === false) {
                throw new \Exception('Part "' . $name . '" could not be encoded');
            }
        }

        $this->startPart($name);
        $this->writeContent(\strval($value));
        $this->endPart();
    }

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
