<?php

namespace OpenRuntimes\Executor;

class RuntimeConflictException extends HttpException
{
    public function __construct(string $message = 'Runtime already exists.', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, 'runtime_conflict', publish: false, previous: $previous);
    }
}
