<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../bin',
        __DIR__ . '/../../config',
        __DIR__ . '/../../features',
        __DIR__ . '/../../src',
        __DIR__ . '/../../tests',
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
    ->withSets([
//        LevelSetList::UP_TO_PHP_85,
//        SetList::PHP_85,

        // Core code quality sets
//        SetList::DEAD_CODE,
//        SetList::CODE_QUALITY,
//        SetList::CODING_STYLE,

        // Type improvements
//        SetList::TYPE_DECLARATION,
//        SetList::TYPE_DECLARATION_DOCBLOCKS,

        // Code structure improvements
//        SetList::EARLY_RETURN,
//        SetList::PRIVATIZATION,
//        SetList::INSTANCEOF,

        // Naming conventions
//        SetList::NAMING,

        // Behat annotations to attributes (project uses Behat)
//        SetList::BEHAT_ANNOTATIONS_TO_ATTRIBUTES,

        // PHPUnit-specific rules
//        PHPUnitSetList::PHPUNIT_120,
//        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
//        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
    ])
    ->withSkip([
        // Generated / compiled files
        // '*/migrations/*',
        '*/var/*',
    ]);
