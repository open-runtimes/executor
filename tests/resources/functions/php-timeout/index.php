<?php

return function ($request, $response) {
    sleep(60);

    return $response->json([
        'pass' => true
    ]);
};