<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__ . '/../../bin')
    ->in(__DIR__ . '/../../config')
    ->in(__DIR__ . '/../../features')
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

return (new Config)
    ->setRiskyAllowed(true)
    ->setRules([
       '@PSR12' => true,
       '@PSR12:risky' => true,

       '@Symfony' => true,
       '@Symfony:risky' => true,

       '@PhpCsFixer' => true,
       '@PhpCsFixer:risky' => true,

       'new_with_parentheses' => false,

       'single_trait_insert_per_statement' => true,       // Strict typing and modernization
       'declare_strict_types' => true,
       'void_return' => true,
       'fully_qualified_strict_types' => true,
       'native_function_invocation' => [
           'include' => ['@internal'],
           'scope' => 'namespaced',
           'strict' => true,
       ],

       'ternary_to_null_coalescing' => true,
       'single_quote' => true,

       // Arrays and lists
       'array_syntax' => ['syntax' => 'short'],
       'list_syntax' => ['syntax' => 'short'],
       'trailing_comma_in_multiline' => [
           'elements' => ['arrays', 'arguments', 'parameters'],
       ],

       // Organization and cleanup
       'ordered_imports' => [
           'sort_algorithm' => 'alpha',
           'imports_order' => ['class', 'function', 'const'],
       ],
       'no_unused_imports' => true,
       'not_operator_with_successor_space' => false,
       'global_namespace_import' => [
           'import_classes' => true,
           'import_constants' => true,
           'import_functions' => false,
       ],

       'phpdoc_to_comment' => false,
       'phpdoc_separation' => true,
       // Ensure proper spacing around unary operators (+, -, !, ~)
       'unary_operator_spaces' => true,
       // Ensure proper spacing around binary operators (+, -, *, /, ==, ===, etc.)
       // Explicitly override @PSR12's 'at_least_single_space' with 'single_space' for strict spacing
       'binary_operator_spaces' => [
           'default' => 'single_space',
           'operators' => [
               '===' => 'single_space',
               '==' => 'single_space',
               '!==' => 'single_space',
               '!=' => 'single_space',
           ],
       ],

       // Disable return_assignment rule - conflicts with PHPStan type inference
       // When a variable is needed for type assertions (@var), keeping it is necessary
       // This pattern is required for PHPStan to properly infer types from complex expressions
       // Type safety takes precedence over this minor code smell
       'return_assignment' => false,
       // Automatically adds type declarations to properties based on @var annotations
       'phpdoc_to_property_type' => true,
       // Automatically adds return type declarations based on @return annotations
       'phpdoc_to_return_type' => true,
       // Ensures consistent ordering: traits, constants, properties, constructor, destructor, magic methods, regular methods
       'ordered_class_elements' => [
           'order' => [
               'use_trait',
               'constant',
               'property',
               'construct',
               'destruct',
               'magic',
               'method',
           ],
       ],
       // Documentation and PHPDoc
       'no_superfluous_phpdoc_tags' => [
           // 'allow_mixed' => true,
           'allow_mixed' => false,
           'remove_inheritdoc' => true,
       ],
       // Ensures consistent ordering of PHPDoc annotations (@param, @return, @throws, etc.)
       'phpdoc_order' => true,
       // Order type hints consistently in PHPDoc
       // Ensures consistent ordering of union types (e.g., null always last)
       'phpdoc_types_order' => [
           'null_adjustment' => 'always_last',  // Always put null last in union types
           'sort_algorithm' => 'none',  // Don't sort, just adjust null position
       ],
       // Standardizes @var type $variable format
       'phpdoc_single_line_var_spacing' => true,
       // Remove variable name from PHPDoc when redundant
       'phpdoc_var_without_name' => true,

       // Add blank line before specific control statements
       'blank_line_before_statement' => [
           'statements' => ['break', 'continue', 'declare', 'for', 'foreach', 'if', 'return', 'throw', 'try'],
       ],
       // Aesthetics and consistency
       'concat_space' => ['spacing' => 'one'],
       // Separate class attributes (properties, methods) with blank lines
       // One blank line between methods for better readability
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
           'keep_multiple_spaces_after_comma' => true,
       ],
       'php_unit_method_casing' => ['case' => 'camel_case'],
       'php_unit_test_case_static_method_calls' => [
           'call_type' => 'this',
       ],
       'strict_comparison' => true,
       'strict_param' => true,

       'single_line_empty_body' => false,
   ])
    ->setFinder($finder)
    ->setUsingCache(true) // Enable caching for faster subsequent runs
    ->setCacheFile(__DIR__ . '/../../var/cache/php-cs-fixer/.php-cs-fixer.cache');

