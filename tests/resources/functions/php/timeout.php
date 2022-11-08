<?php

return function ($request, $response) {
    sleep(15);

    return $response->json([
        'pass' => true
    ]);
};
