<?php


require 'vendor/autoload.php';

use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'https://dummyjson.com'
]);

return function ($context) use ($client) {
    $response = $client->request('GET', '/todos/' . ($context->req->body['id'] ?? 1));
    $todo = \json_decode($response->getBody()->getContents(), true);

    $context->log("Sample Log");

    return $context->res->json([
        'isTest' => true,
        'message' => 'Hello Open Runtimes 👋',
        'variable' => \getenv('TEST_VARIABLE'),
        'url' => $context->req->url,
        'todo' => $todo
    ]);
};
