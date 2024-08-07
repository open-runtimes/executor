<?php

return function ($context) {
    exit(1);
    return $context->res->send('OK');
};
