<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use ReflectionClass;
use RuntimeException;

/**
 * Single source for the "this constant is literal data, not mechanism" tripwire: it reads a class's source,
 * isolates the token stream of one constant's value expression, and classifies tokens as declarative or
 * executable. Two gates need exactly this — the authorization policy's maps and the permission catalog's
 * vocabulary — and the token machinery is identical boilerplate; centralising it here keeps them from
 * drifting apart on what "literal" means, the same reason {@see ApiSourceFiles} centralises the src walk.
 *
 * Declaring a value as `const` already lets the compiler forbid closures and calls; this adds the cheap
 * second line that catches what the compiler still allows. Enum-case and class-constant fetches
 * (`Role::VIEWER->value`, `self::WILDCARD`) are pure data and pass; a `match`, `fn`, call, `?:`, `new Foo`
 * or `?->` slipped into the value would not.
 *
 * `nikic/php-parser` is not on the app autoload, hence {@see \token_get_all()} rather than a real parser.
 *
 * @internal test support
 */
final class ConstantValueTokens
{
    /**
     * Single-character tokens that only appear when a value is computed rather than declared: `(` opens any
     * call / closure / `match` / `new(...)`, `?` opens a ternary.
     */
    private const array FORBIDDEN_CHAR_TOKENS = ['(', '?'];

    /**
     * Keyword/operator tokens that betray executable logic. `(`-based constructs are already caught by
     * {@see self::FORBIDDEN_CHAR_TOKENS}; these are the parenthesis-free ones.
     *
     * @var list<int>
     */
    private const array FORBIDDEN_KEYWORD_TOKENS = [
        T_FN,
        T_FUNCTION,
        T_MATCH,
        T_NEW,
        T_COALESCE,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_IF,
        T_SWITCH,
        T_FOR,
        T_FOREACH,
        T_WHILE,
    ];

    /**
     * The tokens of the named constant's value expression — everything between its `=` and the terminating
     * `;` at bracket depth zero. Empty when the constant is not found in the class source.
     *
     * @param class-string $class
     *
     * @return list<array{int, string, int}|string>
     */
    public static function of(string $class, string $constantName): array
    {
        return self::untilStatementEnd(self::afterAssignmentOf(self::sourceTokensOf($class), $constantName));
    }

    /**
     * @param array{int, string, int}|string $token
     */
    public static function isExecutable(array|string $token): bool
    {
        if (\is_string($token)) {
            return \in_array($token, self::FORBIDDEN_CHAR_TOKENS, true);
        }

        return \in_array($token[0], self::FORBIDDEN_KEYWORD_TOKENS, true);
    }

    /**
     * @param array{int, string, int}|string $token
     */
    public static function describe(array|string $token): string
    {
        return \is_string($token) ? $token : \token_name($token[0]);
    }

    /**
     * Every token following the `=` of the named constant, to end of file. A flat state machine (predicates,
     * not nested conditionals) so it stays under the complexity budget: it arms on `const`, disarms on `;`
     * (so the constant referenced elsewhere as a default never matches), and collects once it sees the `=`.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    private static function afterAssignmentOf(array $tokens, string $constantName): array
    {
        $rest = [];
        $collecting = false;
        $atConstant = false;
        $sawConst = false;

        foreach ($tokens as $token) {
            if ($collecting) {
                $rest[] = $token;
            } elseif (self::isConstKeyword($token)) {
                $sawConst = true;
            } elseif (';' === $token) {
                $sawConst = false;
                $atConstant = false;
            } elseif ($sawConst && self::isNamedString($token, $constantName)) {
                $atConstant = true;
            } elseif ($atConstant && '=' === $token) {
                $collecting = true;
            }
        }

        return $rest;
    }

    /**
     * The prefix of $tokens up to (excluding) the first `;` at array-bracket depth zero. Guarded constants are
     * pure array literals, so only `[`/`]` move the depth.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    private static function untilStatementEnd(array $tokens): array
    {
        $captured = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if ('[' === $token) {
                ++$depth;
            } elseif (']' === $token) {
                --$depth;
            } elseif (';' === $token && 0 === $depth) {
                break;
            }

            $captured[] = $token;
        }

        return $captured;
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private static function isConstKeyword(array|string $token): bool
    {
        return \is_array($token) && T_CONST === $token[0];
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private static function isNamedString(array|string $token, string $name): bool
    {
        return \is_array($token) && T_STRING === $token[0] && $name === $token[1];
    }

    /**
     * @param class-string $class
     *
     * @return list<array{int, string, int}|string>
     */
    private static function sourceTokensOf(string $class): array
    {
        $file = (new ReflectionClass($class))->getFileName();

        if (!\is_string($file)) {
            throw new RuntimeException(\sprintf('Cannot read the source of %s: it has no file.', $class));
        }

        $source = \file_get_contents($file);

        if (!\is_string($source)) {
            throw new RuntimeException(\sprintf('Cannot read the source file of %s.', $class));
        }

        return \token_get_all($source);
    }
}
