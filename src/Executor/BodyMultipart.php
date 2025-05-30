<?php

namespace OpenRuntimes\Executor;

class BodyMultipart
{
    /**
     * @var array<string, mixed> $parts
     */
    private array $parts = [];
    private string $boundary = "";

    public function __construct(?string $boundary = null)
    {
        if (is_null($boundary)) {
            $this->boundary = self::generateBoundary();
        } else {
            $this->boundary = $boundary;
        }
    }

    public static function generateBoundary(): string
    {
        return '-----------------------------' . \uniqid();
    }

    public function load(string $body): self
    {
        $eol =  "\r\n";

        $sections = \explode('--' . $this->boundary, $body);

        foreach ($sections as $section) {
            if (empty($section)) {
                continue;
            }

            if (strpos($section, $eol) === 0) {
                $section = substr($section, \strlen($eol));
            }

            if (substr($section, -2) === $eol) {
                $section = substr($section, 0, -1 * \strlen($eol));
            }

            if ($section == '--') {
                continue;
            }

            $partChunks = \explode($eol . $eol, $section, 2);

            if (\count($partChunks) < 2) {
                continue; // Broken part
            }

            [ $partHeaders, $partBody ] = $partChunks;
            $partHeaders = \explode($eol, $partHeaders);

            $partName = "";
            foreach ($partHeaders as $partHeader) {
                if (!empty($partName)) {
                    break;
                }

                $partHeaderArray = \explode(':', $partHeader, 2);

                $partHeaderName = \strtolower($partHeaderArray[0] ?? '');
                $partHeaderValue = $partHeaderArray[1] ?? '';
                if ($partHeaderName == "content-disposition") {
                    $dispositionChunks = \explode("; ", $partHeaderValue);
                    foreach ($dispositionChunks as $dispositionChunk) {
                        $dispositionChunkValues = \explode("=", $dispositionChunk, 2);
                        if (\count($dispositionChunkValues) >= 2) {
                            if ($dispositionChunkValues[0] === "name") {
                                $partName = \trim($dispositionChunkValues[1], "\"");
                                break;
                            }
                        }
                    }
                }
            }

            if (!empty($partName)) {
                $this->parts[$partName] = $partBody;
            }
        }
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParts(): array
    {
        return $this->parts ?? [];
    }

    public function getPart(string $key, mixed $default = ''): mixed
    {
        return $this->parts[$key] ?? $default;
    }

    public function setPart(string $key, mixed $value): self
    {
        $this->parts[$key] = $value;
        return $this;
    }

    public function getBoundary(): string
    {
        return $this->boundary;
    }

    public function setBoundary(string $boundary): self
    {
        $this->boundary = $boundary;
        return $this;
    }

    public function exportHeader(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    public function exportBody(): string
    {
        $eol =  "\r\n";
        $query = '--' . $this->boundary;

        foreach ($this->parts as $key => $value) {
            $query .= $eol . 'Content-Disposition: form-data; name="' . $key . '"';

            if ($value instanceof \CURLFile) {
                $filename = $value->getPostFilename() ?: \basename($value->getFilename());
                $mime     = $value->getMimeType() ?: 'application/octet-stream';

                $query .= '; filename="' . $filename . '"' . $eol;
                $query .= 'Content-Type: ' . $mime . $eol . $eol;
                $query .= \file_get_contents($value->getFilename()) . $eol;
            } elseif (\is_array($value)) {
                $query .= $eol . 'Content-Type: application/json' . $eol . $eol;
                $query .= \json_encode($value) . $eol;
            } else {
                $query .= $eol . $eol . $value . $eol;
            }

            $query .= '--' . $this->boundary;
        }

        $query .= "--" . $eol;

        return $query;
    }
}
