<?php

namespace OpenRuntimes\Executor;

class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
        public readonly string $type,
        public readonly bool $publish = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
