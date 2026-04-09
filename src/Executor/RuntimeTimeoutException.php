<?php

namespace OpenRuntimes\Executor;

class RuntimeTimeoutException extends HttpException
{
    public function __construct(string $message = 'Timed out waiting for runtime.', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, 'runtime_timeout', previous: $previous);
    }
}
