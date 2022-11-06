<?php

// TODO: @Meldiron Add dependencies to this, to test build step

return function ($request, $response) {
    echo "log1";

    return $response->json([
        'payload' => $request['payload'] ?? '',
        'variable' => $request['variables']['customVariable'] ?? '',
        'unicode' => "Unicode magic: êä"
    ]);
};
