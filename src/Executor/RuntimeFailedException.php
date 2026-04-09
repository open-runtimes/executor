<?php

namespace OpenRuntimes\Executor;

class RuntimeFailedException extends HttpException
{
    public function __construct(string $message = 'Runtime failed.', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, 'runtime_failed', publish: false, previous: $previous);
    }
}
