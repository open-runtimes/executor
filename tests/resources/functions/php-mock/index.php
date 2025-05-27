<?php

require 'vendor/autoload.php';

return function ($context)  {
    $context->log("Sample Log");

    return $context->res->json([
        'isTest' => true,
        'message' => 'Hello Open Runtimes 👋',
        'variable' => \getenv('TEST_VARIABLE'),
        'url' => $context->req->url,
        'todo' => ['userId' => 13]
    ]);
};
