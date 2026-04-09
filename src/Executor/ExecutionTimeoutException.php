<?php

namespace OpenRuntimes\Executor;

class ExecutionTimeoutException extends HttpException
{
    public function __construct(string $message = 'Timed out waiting for execution.', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, 'execution_timeout', previous: $previous);
    }
}
