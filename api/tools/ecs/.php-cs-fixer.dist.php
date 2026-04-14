<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__ . '/../../bin')
    ->in(__DIR__ . '/../../config')
    ->in(__DIR__ . '/../../src')
    ->in(__DIR__ . '/../../tests')
    ->in(__DIR__ . '/../../tools')
    ->in(__DIR__ . '/../../public')
    ->in(__DIR__ . '/../../migrations')
    ->name('*.php')
    ->exclude([
        'var',
        'vendor',
    ])
    ->notName('reference.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
       '@Symfony' => true,
       '@Symfony:risky' => true,
       '@PhpCsFixer' => true,
       '@PhpCsFixer:risky' => true,

       'new_with_parentheses' => [
           'named_class' => false,
           'anonymous_class' => false, // false to keep them
       ],

       // Strict typing and modernization
       'declare_strict_types' => true,
       'void_return' => true,
       'fully_qualified_strict_types' => true,
       'native_function_invocation' => [
           'include' => ['@internal'],
           'scope' => 'namespaced',
           'strict' => true,
       ],

       // Arrays and lists
       'array_syntax' => ['syntax' => 'short'],
       'list_syntax' => ['syntax' => 'short'],
       // 'trailing_comma_in_multiline' => true,
       'trailing_comma_in_multiline' => [
           'elements' => ['arrays', 'arguments', 'parameters'],
       ],

       // Organization and cleanup
       'ordered_imports' => [
           'sort_algorithm' => 'alpha',
           'imports_order' => ['class', 'function', 'const'],
       ],
       'no_unused_imports' => true,
       'global_namespace_import' => [
           'import_classes' => true,
           'import_constants' => true,
           'import_functions' => false,
       ],

       // Documentation and PHPDoc
       'no_superfluous_phpdoc_tags' => [
           // 'allow_mixed' => true,
           'allow_mixed' => false,
           'remove_inheritdoc' => true,
       ],
       'phpdoc_to_comment' => false,
       'phpdoc_separation' => true,
       'phpdoc_order' => true,

       // Aesthetics and consistency
       'concat_space' => ['spacing' => 'one'],
       'class_attributes_separation' => [
           'elements' => [
               'const' => 'one',
               'method' => 'one',
               'property' => 'one',
               'trait_import' => 'none',
           ],
       ],
       'method_argument_space' => [
           'on_multiline' => 'ensure_fully_multiline',
           'keep_multiple_spaces_after_comma' => false,
       ],
       'php_unit_method_casing' => ['case' => 'camel_case'],
       'php_unit_test_case_static_method_calls' => [
           'call_type' => 'this',
       ],
       'strict_comparison' => true,
       'strict_param' => true,
   ])
    ->setFinder($finder);

