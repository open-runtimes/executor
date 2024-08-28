<?php

return function ($request, $response) {
    $response->json([
        'one' => $request->env['one'],
        'two' => $request->env['two'],
        'three' => $request->env['three']
    ]);
};
