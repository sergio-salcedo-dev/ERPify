<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameVariableToMatchNewTypeRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;
use Rector\PHPUnit\CodeQuality\Rector\ClassMethod\NoSetupWithParentCallOverrideRector;

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
        RenameParamToMatchTypeRector::class,
        // Strips #[Override] from setUp()/tearDown() that call their parent, while Psalm's
        // MissingOverrideAttribute (error level) demands the attribute back — skip the rule
        // so the two gates stop fighting over the same line.
        NoSetupWithParentCallOverrideRector::class,
        // PDepend (bundled by phpmd 2.15) cannot parse `new readonly class` —
        // keep the explicit `readonly` on the inner property in the affected
        // file instead, so `make php.md` does not abort on a parser error.
        ReadOnlyAnonymousClassRector::class => [
            __DIR__ . '/../../tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php',
        ],
        RenamePropertyToMatchTypeRector::class,
        RenameVariableToMatchMethodCallReturnTypeRector::class,
        RenameVariableToMatchNewTypeRector::class,
    ])
;
