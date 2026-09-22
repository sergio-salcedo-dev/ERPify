<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Navigating a `token_get_all()` list: argument lists, bracket depth, and the significant token either
 * side of a position.
 *
 * Lexing rather than line matching, for the reason {@see ClassImports} lexes: a call wraps across lines,
 * an argument nests parentheses and brackets, and a comment sits anywhere. Split out of
 * {@see OrderByArguments} when the two concerns together tripped PHPMD's class-complexity threshold —
 * none of this is specific to any one rule, and a second gate reading arguments should not re-derive it.
 *
 * Read only: every method answers a question about the token list and none judges what it finds.
 *
 * @internal test support
 */
final class ArgumentTokens
{
    private const array OPENING = ['(', '[', '{'];

    private const array CLOSING = [')', ']', '}'];

    private const array INSIGNIFICANT = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * The tokens between the opening bracket the list starts on and its match, exclusive.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    public static function insideBrackets(array $tokens): array
    {
        $depth = 0;
        $inside = [];

        foreach ($tokens as $token) {
            if (\in_array($token, self::OPENING, true)) {
                ++$depth;

                if (1 === $depth) {
                    continue;
                }
            }

            if (\in_array($token, self::CLOSING, true)) {
                --$depth;

                if (0 === $depth) {
                    return $inside;
                }
            }

            $inside[] = $token;
        }

        return $inside;
    }

    /**
     * The `$position`-th argument (1-based) of an argument list, split on the commas at the list's own
     * depth so a nested call's commas do not split it. Null when the list has no such argument.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>|null
     */
    public static function argumentAt(array $tokens, int $position): ?array
    {
        $current = 1;
        $argument = [];
        $separators = \array_keys(\iterator_to_array(self::topLevelTokensOf($tokens, ',')));

        foreach ($tokens as $index => $token) {
            if (\in_array($index, $separators, true)) {
                if ($current === $position) {
                    return $argument;
                }

                ++$current;
                $argument = [];

                continue;
            }

            $argument[] = $token;
        }

        return $current === $position && self::holdsSomething($argument) ? $argument : null;
    }

    /**
     * The tokens that belong to the expression itself, keyed by their position, skipping everything a
     * bracket encloses along with the brackets. `$only` narrows the result to one token.
     *
     * Keyed rather than positional so a caller never has to index back into the list it was given —
     * an offset PHPStan cannot prove exists.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return iterable<int, array{int, string, int}|string>
     */
    public static function topLevelTokensOf(array $tokens, ?string $only = null): iterable
    {
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if (\in_array($token, self::OPENING, true)) {
                ++$depth;

                continue;
            }

            if (\in_array($token, self::CLOSING, true)) {
                --$depth;

                continue;
            }

            if (0 !== $depth) {
                continue;
            }

            if (null === $only || $only === $token) {
                yield $index => $token;
            }
        }
    }

    /**
     * True when the last significant token of the list is `->` or `?->`, which is what makes the name
     * after it a member access rather than a function or constant of the same spelling.
     *
     * @param list<array{int, string, int}|string> $preceding
     */
    public static function endsWithObjectOperator(array $preceding): bool
    {
        foreach (\array_reverse($preceding) as $token) {
            if (self::isInsignificant($token)) {
                continue;
            }

            return \is_array($token)
                && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
        }

        return false;
    }

    /**
     * The tokens from `$from` onwards with leading whitespace and comments dropped, so the caller reads
     * the next significant token as element zero.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    public static function significantTail(array $tokens, int $from): array
    {
        $tail = \array_slice($tokens, $from);

        foreach ($tail as $index => $token) {
            if (self::isInsignificant($token)) {
                continue;
            }

            return \array_slice($tail, $index);
        }

        return [];
    }

    /**
     * The tokens' source, whitespace collapsed, for quoting back in a failure message.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    public static function textOf(array $tokens): string
    {
        $text = '';

        foreach ($tokens as $token) {
            $text .= \is_array($token) ? $token[1] : $token;
        }

        return \trim(\preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function holdsSomething(array $tokens): bool
    {
        return \array_any($tokens, static fn (array|string $token): bool => !self::isInsignificant($token));
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private static function isInsignificant(array|string $token): bool
    {
        return \is_array($token) && \in_array($token[0], self::INSIGNIFICANT, true);
    }
}
