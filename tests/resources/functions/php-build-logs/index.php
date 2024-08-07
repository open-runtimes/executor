<?php

// Test never reaches here. It runs logs.sh
return function ($context) {
    return $context->res->send('OK');
};
