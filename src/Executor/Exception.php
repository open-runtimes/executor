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

    public const string EXECUTION_BAD_JSON    = 'execution_bad_json';

    public const string RUNTIME_NOT_FOUND    = 'runtime_not_found';

    public const string RUNTIME_CONFLICT     = 'runtime_conflict';

    public const string RUNTIME_FAILED       = 'runtime_failed';

    public const string RUNTIME_TIMEOUT      = 'runtime_timeout';

    public const string LOGS_TIMEOUT = 'logs_timeout';

    public const string COMMAND_TIMEOUT = 'command_timeout';

    public const string COMMAND_FAILED = 'command_failed';

    protected readonly string $short;

    protected readonly bool $publish;

    /**
     * Constructor for the Exception class.
     *
     * @param string $type The type of exception. This will automatically set fallbacks for the other parameters.
     * @param string|null $message The error message.
     * @param int|null $code The error code.
     * @param \Throwable|null $previous The previous exception.
     */
    public function __construct(
        protected readonly string $type = Exception::GENERAL_UNKNOWN,
        ?string $message = null,
        ?int $code = null,
        ?\Throwable $previous = null
    ) {
        $errors = Config::getParam('errors');
        $error = $errors[$this->type] ?? [];

        $this->message = $message ?? $error['message'];
        $this->code = $code ?? $error['code'] ?: 500;
        $this->short = $error['short'] ?? '';

        $this->publish = $error['publish'] ?? true;

        parent::__construct($this->message, $this->code, $previous);
    }

    /**
     * Get the type of the exception.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the short version of the exception.
     */
    public function getShort(): string
    {
        return $this->short;
    }

    /**
     * Check whether the error message is publishable to logging systems (e.g. Sentry).
     */
    public function isPublishable(): bool
    {
        return $this->publish;
    }
}
