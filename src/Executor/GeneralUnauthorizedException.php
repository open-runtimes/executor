<?php

namespace OpenRuntimes\Executor;

class GeneralUnauthorizedException extends HttpException
{
    public function __construct(string $message = 'You are not authorized to access this resource.', ?\Throwable $previous = null)
    {
        parent::__construct(401, $message, 'general_unauthorized', previous: $previous);
    }
}
