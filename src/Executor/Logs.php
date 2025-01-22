<?php

namespace OpenRuntimes\Executor;

use DateInterval;
use DateTime;
use DateTimeZone;

class Logs
{
    /**
     * @return array<array<string, string>>
     */
    public static function getLogs(string $containerId): array
    {
        $output = [];

        $dir = "/tmp/$containerId/logging";
        $logsFile = $dir . "/logs.txt";
        $timingsFile = $dir . "/timings.txt";

        if (!\file_exists($logsFile) || !\file_exists($timingsFile)) {
            return [];
        }

        $logs = \file_get_contents($logsFile) ?: '';
        $timings = \file_get_contents($timingsFile) ?: '';

        $offset = 0; // Current offset from timing for reading logs content
        $tempLogsContentSplit = \explode("\n", $logs, 2); // Find first linebreak to identify prefix
        $introOffset = \strlen($tempLogsContentSplit[0]); // Ignore script addition "Script started on..."
        $introOffset += 1; // Consider linebreak an intro too

        $datetime = new DateTime("now", new DateTimeZone("UTC")); // Date used for tracking absolute log timing
        $rows = \explode("\n", $timings);
        foreach ($rows as $row) {
            if (empty($row)) {
                continue;
            }

            [$timing, $length] = \explode(' ', $row, 2);
            $timing = \floatval($timing);
            $timing = \ceil($timing * 1000000); // Convert to microseconds
            $length = \intval($length);

            $di = DateInterval::createFromDateString($timing . ' microseconds');
            $datetime->add($di);

            $timingContent = $datetime->format('Y-m-d\TH:i:s.vP');

            $logContent = \substr($logs, $introOffset + $offset, \abs($length)) ?: '';

            $putput[] = [
                'timestamp' => $timingContent,
                'content' => $logContent
            ];

            $offset += $length;
        }

        return $output;
    }
}
