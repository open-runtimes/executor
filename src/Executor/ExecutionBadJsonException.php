<?php

namespace OpenRuntimes\Executor;

class ExecutionBadJsonException extends HttpException
{
    public function __construct(string $message = 'Execution resulted in binary response, but JSON response does not allow binaries. Use "Accept: multipart/form-data" header to support binaries.', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, 'execution_bad_json', previous: $previous);
    }
}
