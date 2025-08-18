<?php

use OpenRuntimes\Executor\Exception;
use Utopia\CLI\Console;
use Utopia\Http\Http;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Http\Response;

function logError(Log $log, Throwable $error, ?Logger $logger = null): void
{
    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());

    if ($logger === null) {
        return;
    }

    // Log everything, except those explicitly marked as not loggable
    if ($error instanceof Exception && !$error->isLoggable()) {
        return;
    }

    try {
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());
        $log->setAction("httpError");
        $log->addTag('code', \strval($error->getCode()));
        $log->addTag('verboseType', get_class($error));
        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());

        $status = $logger->addLog($log);

        Console::info("Pushed log with response status code: $status");
    } catch (\Throwable $e) {
        Console::error("Failed to push log: {$e->getMessage()}");
    }
}

Http::error()
    ->inject('error')
    ->inject('logger')
    ->inject('response')
    ->inject('log')
    ->action(function (Throwable $error, ?Logger $logger, Response $response, Log $log) {
        logError($log, $error, $logger);

        // Show all errors in development mode.
        // Otherwise, only show Executor type exceptions, that are also marked as public.
        $public = Http::isDevelopment() || ($error instanceof Exception && $error->isPublic());
        $exception = $public ? $error : new Exception(Exception::GENERAL_UNKNOWN);

        $output = [
            'type' => $error instanceof Exception ? $error->getType() : Exception::GENERAL_UNKNOWN,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'version' => Http::getEnv('OPR_EXECUTOR_VERSION', 'unknown')
        ];

        // If in development, include some additional details.
        if (Http::isDevelopment()) {
            $output['file'] = $exception->getFile();
            $output['line'] = $exception->getLine();
            $output['trace'] = \json_encode($exception->getTrace(), JSON_UNESCAPED_UNICODE) === false ? [] : $exception->getTrace();
        }

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($exception->getCode())
            ->json($output);
    });
