<?php

namespace OpenRuntimes\Executor;

use DateInterval;
use DateTime;
use DateTimeZone;

class Logs
{
    /**
     * @return array<array<string, mixed>>
     */
    public static function get(string $containerId): array
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
        $introOffset = self::getLogOffset($logs);

        $parts = self::parseTiming($timings);

        foreach ($parts as $part) {
            $timestamp = $part['timestamp'] ?? '';
            $length = \intval($part['length'] ?? '0');

            if ($offset >= MAX_BUILD_LOG_SIZE) {
                $output[] = [
                    'timestamp' => $timestamp,
                    'content' => 'Logs truncated due to size exceeding ' . number_format(MAX_LOG_SIZE / 1048576, 2) . 'MB.',
                ];
                break;
            }

            $logContent = \substr($logs, $introOffset + $offset, \abs($length)) ?: '';

            $output[] = [
                'timestamp' => $timestamp,
                'content' => $logContent
            ];

            $offset += $length;
        }

        return $output;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public static function parseTiming(string $timing, ?DateTime $datetime = null): array
    {
        if (\is_null($datetime)) {
            $datetime = new DateTime("now", new DateTimeZone("UTC")); // Date used for tracking absolute log timing
        }

        if (empty($timing)) {
            return [];
        }

        $parts = [];

        $rows = \explode("\n", $timing);
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

            $date = $datetime->format('Y-m-d\TH:i:s.vP');

            $parts[] = [
                'timestamp' => $date,
                'length' => $length
            ];
        }

        return $parts;
    }

    public static function getLogOffset(string $logs): int
    {
        $contentSplit = \explode("\n", $logs, 2); // Find first linebreak to identify prefix
        $offset = \strlen($contentSplit[0] ?? ''); // Ignore script addition "Script started on..."
        $offset += 1; // Consider linebreak an intro too

        return $offset;
    }

    public static function getTimestamp(): string
    {
        return (new DateTime("now", new DateTimeZone("UTC")))->format('Y-m-d\TH:i:s.vP');
    }
}
