<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Reads ONE signature out of a token stream: the parameter names, and every type token of the parameter
 * list and the return type.
 *
 * Split from {@see PublicSignatures}, which answers a different question — WHICH methods are public — and
 * needs none of the bracket arithmetic below to answer it. Keeping both in one class made a reader hold two
 * unrelated state machines at once.
 *
 * @internal test support
 */
final class SignatureReader
{
    /** The token types a type name is lexed into, in a parameter list or after the return colon. */
    private const array NAME_TOKENS = [
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
        T_ARRAY,
        T_CALLABLE,
        T_STATIC,
    ];

    /**
     * Two passes rather than one loop, because the two jobs have nothing to say to each other: finding
     * where the parameter list ENDS is bracket arithmetic over every depth, and reading what is IN it is a
     * small state machine over one depth.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{parameters: list<string>, types: list<string>}
     */
    public static function at(array $tokens, int $index): array
    {
        [$slice, $closingParenthesis] = self::parameterListAfter($tokens, $index);
        $collected = self::collect($slice);

        return [
            'parameters' => $collected['parameters'],
            'types' => [...$collected['types'], ...self::returnTypeAfter($tokens, $closingParenthesis)],
        ];
    }

    /**
     * The tokens sitting at the TOP level of the parameter list, plus the index of the `)` that closes it.
     *
     * Depth is tracked across every bracket so a default value holding its own parentheses or brackets
     * cannot end the list early, and only depth-1 tokens are kept, so an attribute's arguments and a nested
     * array literal never reach the reader.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{0: list<array{int, string, int}|string>, 1: int}
     */
    private static function parameterListAfter(array $tokens, int $index): array
    {
        $slice = [];
        $depth = 0;
        $openers = [];
        $total = \count($tokens);

        for ($cursor = $index; $cursor < $total; ++$cursor) {
            $token = $tokens[$cursor] ?? null;

            if (null === $token) {
                return [$slice, $cursor];
            }

            if (self::opensABracket($token)) {
                $openers[] = \is_array($token) ? 'attribute' : $token;
            }

            $depth += self::depthDeltaOf($token);

            if (0 === $depth && self::closesABracket($token)) {
                return [$slice, $cursor];
            }

            if (self::isCollectable($depth, $openers, $token)) {
                $slice[] = $token;
            }

            if (self::closesABracket($token)) {
                \array_pop($openers);
            }
        }

        return [$slice, $total];
    }

    /**
     * Reads names and types out of a flat parameter list. A `=` suppresses collection until the next comma,
     * so a default naming a class constant is never read as a type.
     *
     * @param list<array{int, string, int}|string> $slice
     *
     * @return array{parameters: list<string>, types: list<string>}
     */
    private static function collect(array $slice): array
    {
        $parameters = [];
        $types = [];
        $inDefault = false;

        foreach ($slice as $slouse) {
            if (',' === $slouse) {
                $inDefault = false;
            } elseif ('=' === $slouse) {
                $inDefault = true;
            } elseif (!$inDefault && \is_array($slouse)) {
                if (T_VARIABLE === $slouse[0]) {
                    $parameters[] = \ltrim($slouse[1], '$');
                } elseif (\in_array($slouse[0], self::NAME_TOKENS, true)) {
                    $types[] = $slouse[1];
                }
            }
        }

        return ['parameters' => $parameters, 'types' => $types];
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<string>
     */
    private static function returnTypeAfter(array $tokens, int $closingParenthesis): array
    {
        $types = [];
        $counter = \count($tokens);

        for ($cursor = $closingParenthesis + 1; $cursor < $counter; ++$cursor) {
            $token = $tokens[$cursor] ?? null;

            if (null === $token || '{' === $token || ';' === $token) {
                break;
            }

            if (\is_array($token) && \in_array($token[0], self::NAME_TOKENS, true)) {
                $types[] = $token[1];
            }
        }

        return $types;
    }

    /**
     * Depth 1 is the parameter list itself. Depth 2 is admitted for ONE shape — a `(` nested directly in the
     * list — because that is a DNF type: `(SplFileInfo&Countable)|string $file` puts its members one level
     * down, and dropping them lost `SplFileInfo` from a scan whose whole subject is which types cross a
     * boundary. Everything else at depth 2 stays excluded, and an ATTRIBUTE's arguments are excluded at any
     * depth: `#[Foo(Bar::class)]` must not contribute `Bar` as a parameter type.
     *
     * A default value's parentheses reach here too and are harmless — {@see self::collect()} suppresses
     * collection from `=` to the next comma, so nothing inside one is read as a type.
     *
     * @param list<string>                   $openers
     * @param array{int, string, int}|string $token
     */
    private static function isCollectable(int $depth, array $openers, array|string $token): bool
    {
        if (self::opensABracket($token) || \in_array('attribute', $openers, true)) {
            return false;
        }

        if (1 === $depth) {
            return true;
        }

        return 2 === $depth && '(' === ($openers[\count($openers) - 1] ?? null);
    }

    /**
     * `#[` lexes as one T_ATTRIBUTE token rather than as the bracket it opens, so counting only the literal
     * `[` let a parameter attribute's closing bracket end the list: measured, every parameter after a
     * `#[SensitiveParameter]` disappeared and the return type was read out of the middle of the list.
     *
     * @param array{int, string, int}|string $token
     */
    private static function depthDeltaOf(array|string $token): int
    {
        if (self::opensABracket($token)) {
            return 1;
        }

        return self::closesABracket($token) ? -1 : 0;
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private static function opensABracket(array|string $token): bool
    {
        return '(' === $token || '[' === $token || (\is_array($token) && T_ATTRIBUTE === $token[0]);
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private static function closesABracket(array|string $token): bool
    {
        return ')' === $token || ']' === $token;
    }
}
