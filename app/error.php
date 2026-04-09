<?php

use OpenRuntimes\Executor\HttpException;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\System\System;

Http::error()
    ->inject('error')
    ->inject('response')
    ->action(function (Throwable $error, Response $response): void {
        if ($error instanceof HttpException) {
            if ($error->publish) {
                // TODO: publish to logger/Sentry
            }

            $code = $error->statusCode;
            $output = [
                'type'    => $error->type,
                'message' => $error->getMessage(),
                'code'    => $code,
                'version' => System::getEnv('OPR_EXECUTOR_VERSION', 'unknown'),
            ];
        } else {
            // TODO: always publish to logger/Sentry
            $code = 500;
            $output = [
                'type'    => 'general_unknown',
                'message' => Http::isDevelopment() ? $error->getMessage() : 'Internal server error.',
                'code'    => 500,
                'version' => System::getEnv('OPR_EXECUTOR_VERSION', 'unknown'),
            ];
        }

        if (Http::isDevelopment()) {
            $output['file'] = $error->getFile();
            $output['line'] = $error->getLine();
            $output['trace'] = \json_encode($error->getTrace(), JSON_UNESCAPED_UNICODE) === false ? [] : $error->getTrace();
        }

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($code)
            ->json($output);
    });
