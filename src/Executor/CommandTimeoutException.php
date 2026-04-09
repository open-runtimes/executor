<?php

namespace OpenRuntimes\Executor;

class CommandTimeoutException extends HttpException
{
    public function __construct(string $message = 'Operation timed out.', ?\Throwable $previous = null)
    {
        parent::__construct(500, $message, 'command_timeout', previous: $previous);
    }
}
