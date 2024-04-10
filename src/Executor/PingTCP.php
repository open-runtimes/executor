<?php

namespace OpenRuntimes\Executor;

class PingTCP
{
    public static function isUp(string $ip, int $port): bool
    {
        $errorCode = null;
        $errorMessage = "";
        $socket = \fsockopen($ip, $port, $errorCode, $errorMessage, 10);

        if (!$socket) {
            return false;
        } else {
            \fclose($socket);
            return true;
        }
    }
}
