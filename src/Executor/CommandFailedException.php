<?php

namespace OpenRuntimes\Executor;

class CommandFailedException extends HttpException
{
    public function __construct(string $message = 'Failed to execute command.', ?\Throwable $previous = null)
    {
        parent::__construct(500, $message, 'command_failed', previous: $previous);
    }
}
