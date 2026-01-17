<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/app',
        __DIR__ . '/tests'
    ])
    ->withSkipPath(__DIR__ . '/tests/resources')
    ->withPhpSets(php84: true)
    ->withComposerBased(phpunit: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        phpunitCodeQuality: true,
        earlyReturn: true
    );
