<?php 

require 'vendor/autoload.php';

use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'https://jsonplaceholder.typicode.com'
]);

return function($req, $res) use ($client) {
    $payload = \json_decode($req['payload'] === '' ? '{}' : $req['payload'], true);

    $response = $client->request('GET', '/todos/' . ($payload['id'] ?? 1));
    $todo = \json_decode($response->getBody()->getContents(), true);

    echo "Sample Log";
    
    $res->json([
        'isTest' => true,
        'message' => 'Hello Open Runtimes 👋',
        'variable' => $req['variables']['test-variable'],
        'todo' => $todo
    ]);
};