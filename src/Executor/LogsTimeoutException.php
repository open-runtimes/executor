<?php

namespace OpenRuntimes\Executor;

class LogsTimeoutException extends HttpException
{
    public function __construct(string $message = 'Timed out waiting for logs.', ?\Throwable $previous = null)
    {
        parent::__construct(504, $message, 'logs_timeout', previous: $previous);
    }
}
