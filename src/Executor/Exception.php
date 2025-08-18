<?php

namespace OpenRuntimes\Executor;

use Utopia\Config\Config;

class Exception extends \RuntimeException
{
    /**
     * Error Codes
     *
     * Naming the error types based on the following convention
     * <ENTITY>_<ERROR_TYPE>
     */
    public const string GENERAL_UNKNOWN         = 'general_unknown';
    public const string GENERAL_ROUTE_NOT_FOUND = 'general_route_not_found';
    public const string GENERAL_UNAUTHORIZED    = 'general_unauthorized';

    public const string EXECUTION_BAD_REQUEST = 'execution_bad_request';
    public const string EXECUTION_TIMEOUT     = 'execution_timeout';
    public const string EXECUTION_BAD_JSON    = 'execution_bad_josn';

    public const string RUNTIME_NOT_FOUND    = 'runtime_not_found';
    public const string RUNTIME_CONFLICT     = 'runtime_conflict';
    public const string RUNTIME_START_FAILED = 'runtime_start_failed';
    public const string RUNTIME_FAILED       = 'runtime_failed';
    public const string RUNTIME_NOT_READY    = 'runtime_not_ready';
    public const string RUNTIME_TIMEOUT      = 'runtime_timeout';

    public const string LOGS_TIMEOUT = 'logs_timeout';

    public const string COMMAND_TIMEOUT = 'command_timeout';
    public const string COMMAND_FAILED = 'command_failed';

    protected readonly string $type;
    protected readonly string $short;
    protected readonly bool $public;
    protected readonly bool $loggable;

    public function __construct(
        string $type = Exception::GENERAL_UNKNOWN,
        ?string $message = null,
        ?int $code = null,
        ?\Throwable $previous = null
    ) {
        $errors = Config::getParam('errors');

        $this->type = $type;
        $error = $errors[$type] ?? [];

        $this->message = $message ?? $error['message'];
        $this->code = $code ?? $error['code'] ?: 500;
        $this->short = $error['short'] ?? '';

        $this->public = $error['public'] ?? true;
        $this->loggable = $error['loggable'] ?? true;

        parent::__construct($this->message, $this->code, $previous);
    }

    /**
     * Get the type of the exception.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the short version of the exception.
     *
     * @return string
     */
    public function getShort(): string
    {
        return $this->short;
    }

    /**
     * Check whether the error message is public.
     *
     * @return bool
     */
    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * Check whether the error is loggable.
     *
     * @return bool
     */
    public function isLoggable(): bool
    {
        return $this->loggable;
    }
}
