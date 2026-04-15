<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../bin',
        __DIR__ . '/../../config',
        __DIR__ . '/../../features',
        __DIR__ . '/../../src',
        __DIR__ . '/../../tests',
        __DIR__ . '/../../tools',
        __DIR__ . '/../../public',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        naming: true,
        instanceOf: true,
        earlyReturn: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
        symfonyConfigs: true,
    )
    ->withPhpSets(php85: true)
    ->withAttributesSets(
        symfony: true,
        doctrine: true,
        phpunit: true,
        fosRest: true,
        jms: true,
        sensiolabs: true,
        behat: true,
    )
    ->withComposerBased(
        doctrine: true,
        phpunit: true,
        symfony: true,
    )
    ->withSkip([
        '*/var/*',
        '*/vendor/*',
        '**/config/reference.php',
        // Do not simplify (new Class())->method()
        NewMethodCallWithoutParenthesesRector::class,
    ])
;
