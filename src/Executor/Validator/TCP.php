<?php

namespace OpenRuntimes\Executor\Validator;

use Utopia\Validator;

/**
 * TCP ping validator
 *
 * Validate that a port is open on an IP address.
 */
class TCP extends Validator
{
    public function __construct(protected float $timeout = 10)
    {
    }

    public function getDescription(): string
    {
        return 'Host is unreachable, or port is not open.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid(mixed $value): bool
    {
        try {
            $value = \strval($value);
            [ $ip, $port ] = \explode(':', $value);
            $port = \intval($port);

            if ($port === 0 || ($ip === '' || $ip === '0')) {
                return false;
            }

            // TCP Ping
            $errorCode = null;
            $errorMessage = "";
            $socket = @\fsockopen($ip, $port, $errorCode, $errorMessage, $this->timeout); // @ prevents warnings (Unable to connect)

            if (!$socket) {
                return false;
            }

            \fclose($socket);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
