<?php

namespace OpenRuntimes\Executor;

class ExecutionBadRequestException extends HttpException
{
    public function __construct(string $message = 'Execution request was invalid.', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, 'execution_bad_request', previous: $previous);
    }
}
