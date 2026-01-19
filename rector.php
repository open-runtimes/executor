<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Privatization\Rector\Class_\FinalizeTestCaseClassRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/app',
        __DIR__ . '/tests'
    ])
    ->withSkipPath(__DIR__ . '/tests/resources')
    ->withSkip([
        FinalizeTestCaseClassRector::class,
    ])
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
