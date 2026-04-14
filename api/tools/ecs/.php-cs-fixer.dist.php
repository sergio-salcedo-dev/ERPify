<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__ . '/../../src')
    ->in(__DIR__ . '/../../tests')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'remove_inheritdoc' => true],
        'phpdoc_separation' => true,
        'concat_space' => ['spacing' => 'one'],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'trailing_comma_in_multiline' => true,
        'void_return' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
