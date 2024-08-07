<?php

// Test never reaches here. It runs bash scripts
return function ($context) {
    return $context->res->send('OK');
};
