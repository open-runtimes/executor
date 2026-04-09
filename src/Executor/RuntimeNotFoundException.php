<?php

namespace OpenRuntimes\Executor;

class RuntimeNotFoundException extends HttpException
{
    public function __construct(string $message = 'Runtime not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'runtime_not_found', previous: $previous);
    }
}
