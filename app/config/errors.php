<?php

use OpenRuntimes\Executor\Exception;

return [
    /* General */
    Exception::GENERAL_UNKNOWN => [
        'name' => Exception::GENERAL_UNKNOWN,
        'short' => 'Whoops',
        'message' => 'Internal server error.',
        'code' => 500,
    ],
    Exception::GENERAL_ROUTE_NOT_FOUND => [
        'name' => Exception::GENERAL_ROUTE_NOT_FOUND,
        'short' => 'Not found',
        'message' => 'The requested route was not found.',
        'code' => 404,
    ],
    Exception::GENERAL_UNAUTHORIZED => [
        'name' => Exception::GENERAL_UNAUTHORIZED,
        'short' => 'Unauthorized',
        'message' => 'You are not authorized to access this resource.',
        'code' => 401,
    ],
    /* Runtime */
    Exception::RUNTIME_FAILED  => [
        'name' => Exception::RUNTIME_FAILED,
        'short' => 'Failed',
        'message' => 'Runtime failed.',
        'code' => 400,
    ],
    Exception::RUNTIME_NOT_FOUND  => [
        'name' => Exception::RUNTIME_CONFLICT,
        'short' => 'Not found',
        'message' => 'Runtime not found',
        'code' => 404,
    ],
    Exception::RUNTIME_CONFLICT  => [
        'name' => Exception::RUNTIME_CONFLICT,
        'short' => 'Conflict',
        'message' => 'Runtime already exists ',
        'code' => 409,
    ],
    Exception::RUNTIME_START_FAILED  => [
        'name' => Exception::RUNTIME_START_FAILED,
        'short' => 'Failed',
        'message' => 'Runtime start failed.',
        'code' => 500,
    ],
    Exception::RUNTIME_NOT_READY => [
        'name' => Exception::RUNTIME_TIMEOUT,
        'short' => 'Not ready',
        'message' => 'Runtime not ready. Container not found.',
        'code' => 500,
    ],
    Exception::RUNTIME_TIMEOUT => [
        'name' => Exception::RUNTIME_TIMEOUT,
        'short' => 'Timeout',
        'message' => 'Timed out waiting for runtime.',
        'code' => 504,
    ],
    /* Execution */
    Exception::EXECUTION_BAD_REQUEST => [
        'name' => Exception::EXECUTION_BAD_REQUEST,
        'short' => 'Invalid request',
        'message' => 'Execution request was invalid.',
        'code' => 400,
    ],
    Exception::EXECUTION_BAD_JSON => [
        'name' => Exception::EXECUTION_BAD_JSON,
        'short' => 'Invalid response',
        'message' => 'Execution resulted in binary response, but JSON response does not allow binaries. Use "Accept: multipart/form-data" header to support binaries.',
        'code' => 400,
    ],
    Exception::EXECUTION_TIMEOUT => [
        'name' => Exception::EXECUTION_TIMEOUT,
        'short' => 'Timeout',
        'message' => 'Timed out waiting for execution.',
        'code' => 504,
    ],
    /* Logs */
    Exception::LOGS_TIMEOUT => [
        'name' => Exception::LOGS_TIMEOUT,
        'short' => 'Timeout',
        'message' => 'Timed out waiting for logs.',
        'code' => 504,
    ],
    /* Command */
    Exception::COMMAND_TIMEOUT => [
        'name' => Exception::COMMAND_TIMEOUT,
        'short' => 'Timeout',
        'message' => 'Operation timed out.',
        'code' => 500,
    ],
    Exception::COMMAND_FAILED => [
        'name' => Exception::COMMAND_FAILED,
        'short' => 'Failed',
        'message' => 'Failed to execute command.',
        'code' => 500,
    ],
];
