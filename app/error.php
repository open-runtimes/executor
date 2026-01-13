<?php

use OpenRuntimes\Executor\Exception;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\System\System;

Http::error()
    ->inject('error')
    ->inject('response')
    ->action(function (Throwable $error, Response $response) {
        // Show all Executor\Exceptions, or everything if in development
        $public = $error instanceof Exception || Http::isDevelopment();
        $exception = $public ? $error : new Exception(Exception::GENERAL_UNKNOWN);
        $code = $exception->getCode() ?: 500;

        $output = [
            'type' => $exception instanceof Exception ? $exception->getType() : Exception::GENERAL_UNKNOWN,
            'message' => $exception->getMessage(),
            'code' => $code,
            'version' => System::getEnv('OPR_EXECUTOR_VERSION', 'unknown')
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
            ->setStatusCode($code)
            ->json($output);
    });
