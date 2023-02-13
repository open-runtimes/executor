<?php

return function ($context) {
    sleep(60);

    return $context['res']->json([
        'pass' => true
    ]);
};