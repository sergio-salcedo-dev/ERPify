<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameVariableToMatchNewTypeRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\AddSeeTestAnnotationRector;
use Rector\PHPUnit\PHPUnit120\Rector\Class_\AllowMockObjectsForDataProviderRector;
use Rector\Symfony\Symfony73\Rector\Class_\CommandHelpToAttributeRector;

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
        // Its detector matches any call named `method()` inside a test carrying
        // #[DataProvider], without checking the receiver is a MockObject — so it fires on
        // createStub(), which is not one. PHPUnit runs here with failOnDeprecation,
        // failOnNotice and failOnWarning all on, and the affected classes are green, so the
        // attribute would suppress a deprecation this tree does not emit.
        AllowMockObjectsForDataProviderRector::class,
        // Moving setHelp() into #[AsCommand] splits one command's description across two
        // places: configure() survives regardless, holding addArgument/addOption/addUsage.
        // The heredoc also loses its indentation as an attribute argument and collapses the
        // attribute past the 120-character line limit.
        CommandHelpToAttributeRector::class,
        // Do not simplify (new Class())->method()
        NewMethodCallWithoutParenthesesRector::class,
        RenameParamToMatchTypeRector::class,
        // PDepend (bundled by phpmd 2.15) cannot parse `new readonly class` —
        // keep the explicit `readonly` on the inner property in the affected
        // file instead, so `make php.md` does not abort on a parser error.
        ReadOnlyAnonymousClassRector::class => [
            __DIR__ . '/../../tests/Unit/Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponderTest.php',
        ],
        RenamePropertyToMatchTypeRector::class,
        // Non-convergent against php-cs-fixer's `fully_qualified_strict_types`, which is on: Rector adds
        // `@see \Fully\Qualified\SubjectTest`, the fixer shortens it to `SubjectTest`, and the next run no
        // longer recognises it and appends another — measured, one duplicate line per `make php.quality`.
        // Nothing in `src` pairs a class with a same-named test today, so the rule fires only on the
        // subject/test pairs inside `tests/`, where the annotation buys least: the test names its subject in
        // its own docblock and sits at the mirrored path.
        AddSeeTestAnnotationRector::class,
        RenameVariableToMatchMethodCallReturnTypeRector::class,
        RenameVariableToMatchNewTypeRector::class,
    ])
;
