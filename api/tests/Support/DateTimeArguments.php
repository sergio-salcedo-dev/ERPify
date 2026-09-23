<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * How {@see WallClockReads} reads the argument list of a date constructor or date function: whether it names an
 * instant or leaves PHP to resolve one against "now", and how many arguments it carries.
 *
 * An absolute instant is an ISO-8601 date (`2026-01-01…`) or a Unix timestamp (`'@' . $ts`); anything else a
 * literal spells is resolved against the wall clock, or might be.
 *
 * @internal test support
 */
final class DateTimeArguments
{
    private const string ABSOLUTE = '/^\s*(?:@|\d{4}-\d{2}-\d{2})/';

    /**
     * True when the argument list starting at `(` carries no `datetime` argument, or one that starts with a
     * relative string.
     *
     * @param list<array{int, string, int}|string> $fromParenthesis
     */
    public static function readsNow(array $fromParenthesis): bool
    {
        $datetime = self::datetimeArgument(ArgumentTokens::insideBrackets($fromParenthesis));

        if (null === $datetime) {
            return true;
        }

        $head = $datetime[0] ?? null;
        $body = $datetime[1] ?? null;

        return match (true) {
            \is_array($head) && T_CONSTANT_ENCAPSED_STRING === $head[0] => self::isRelative(\substr($head[1], 1, -1)),
            ('"' === $head || (\is_array($head) && T_START_HEREDOC === $head[0]))
                && \is_array($body) && T_ENCAPSED_AND_WHITESPACE === $body[0] => self::isRelative($body[1]),
            default => false,
        };
    }

    /**
     * The tokens of the `datetime` argument — the first positional one, or the one named `datetime:` — or null
     * when the call passes none, which makes the constructor read "now".
     *
     * @param list<array{int, string, int}|string> $arguments
     *
     * @return list<array{int, string, int}|string>|null
     */
    private static function datetimeArgument(array $arguments): ?array
    {
        for ($position = 1; null !== ($argument = ArgumentTokens::argumentAt($arguments, $position)); ++$position) {
            $label = self::namedArgumentLabel($argument);

            if (null === $label) {
                return 1 === $position && [] !== $argument ? $argument : null;
            }

            if ('datetime' === $label) {
                return \array_slice($argument, 2);
            }
        }

        return null;
    }

    /**
     * @param list<array{int, string, int}|string> $argument
     */
    private static function namedArgumentLabel(array $argument): ?string
    {
        $label = $argument[0] ?? null;

        return \is_array($label) && T_STRING === $label[0] && ':' === ($argument[1] ?? null)
            ? \strtolower($label[1])
            : null;
    }

    /**
     * @param list<array{int, string, int}|string> $fromParenthesis
     */
    public static function argumentCount(array $fromParenthesis): int
    {
        $inside = ArgumentTokens::insideBrackets($fromParenthesis);

        if ([] === $inside) {
            return 0;
        }

        $trailingComma = ',' === $inside[\count($inside) - 1] ? 1 : 0;

        return 1 + \iterator_count(ArgumentTokens::topLevelTokensOf($inside, ',')) - $trailingComma;
    }

    private static function isRelative(string $literal): bool
    {
        return 1 !== \preg_match(self::ABSOLUTE, $literal);
    }
}
