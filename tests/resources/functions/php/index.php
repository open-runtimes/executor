<?php


require 'vendor/autoload.php';

return function ($context) {
    $context->log("Sample Log");

    return $context->res->json([
        'isTest' => true,
        'message' => 'Hello Open Runtimes 👋',
        'variable' => \getenv('TEST_VARIABLE'),
        'url' => $context->req->url,
        'todo' => [
            'id' => (int) ($context->req->body['id'] ?? 1),
            'todo' => 'Use a local fixture for executor tests.',
            'completed' => false,
            'userId' => 13,
        ],
    ], 200, [
        'x-key' => 'aValue'
    ]);
};
