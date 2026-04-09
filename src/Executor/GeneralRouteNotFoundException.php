<?php

namespace OpenRuntimes\Executor;

class GeneralRouteNotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, 'general_route_not_found', previous: $previous);
    }
}
