<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * The names a PHP source brings into scope with `use`, keyed by the alias the code spells, so a reader can
 * resolve `new Dt()` after `use DateTimeImmutable as Dt;` or `SymfonyClock::get()` after
 * `use Symfony\Component\Clock\Clock as SymfonyClock;` to the class actually named.
 *
 * Lexed rather than line-matched, so a grouped import (`use Symfony\Component\Clock\{Clock, DatePoint};`), an
 * indented import inside a braced namespace and an alias are one statement shape. A closure's `use (...)`
 * imports nothing and ends at its parenthesis. Everything is lower-cased and carries no leading separator,
 * because PHP resolves names case-insensitively.
 *
 * @internal test support
 */
final class ImportAliases
{
    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR];

    /**
     * @param list<array{int, string, int}|string> $tokens significant tokens only
     *
     * @return array{classes: array<string, string>, functions: array<string, string>} alias => qualified name
     */
    public static function of(array $tokens): array
    {
        $aliases = ['classes' => [], 'functions' => []];

        foreach (self::statements($tokens) as $statement) {
            $kind = 'classes';

            if (\is_array($statement[0] ?? null) && T_FUNCTION === $statement[0][0]) {
                $kind = 'functions';
                \array_shift($statement);
            }

            foreach (self::entries($statement) as $alias => $name) {
                $aliases[$kind][$alias] = $name;
            }
        }

        return $aliases;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<list<array{int, string, int}|string>>
     */
    private static function statements(array $tokens): array
    {
        $statements = [];
        $current = null;

        foreach ($tokens as $token) {
            if (null === $current) {
                $current = \is_array($token) && T_USE === $token[0] ? [] : null;

                continue;
            }

            if (';' === $token || ('(' === $token && [] === $current)) {
                $statements[] = $current;
                $current = null;

                continue;
            }

            $current[] = $token;
        }

        return \array_values(\array_filter($statements, static fn (array $statement): bool => [] !== $statement));
    }

    /**
     * One statement's `alias => qualified name` pairs, expanding a group against its prefix.
     *
     * @param list<array{int, string, int}|string> $statement
     *
     * @return array<string, string>
     */
    private static function entries(array $statement): array
    {
        $prefix = '';
        $entries = [];
        $current = [];

        foreach ($statement as $token) {
            if ('{' === $token) {
                $prefix = self::spelling($current);
                $current = [];

                continue;
            }

            if (',' === $token || '}' === $token) {
                $entries += self::entry($prefix, $current);
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        return $entries + self::entry($prefix, $current);
    }

    /**
     * @param list<array{int, string, int}|string> $tokens a name, optionally followed by `as <alias>`
     *
     * @return array<string, string>
     */
    private static function entry(string $prefix, array $tokens): array
    {
        $name = [];
        $alias = null;

        foreach ($tokens as $position => $token) {
            if (\is_array($token) && T_AS === $token[0]) {
                $next = $tokens[$position + 1] ?? null;
                $alias = \is_array($next) ? \strtolower($next[1]) : null;

                break;
            }

            $name[] = $token;
        }

        $qualified = \trim($prefix . self::spelling($name), '\\');

        if ('' === $qualified) {
            return [];
        }

        $segments = \explode('\\', $qualified);

        return [$alias ?? \end($segments) => $qualified];
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function spelling(array $tokens): string
    {
        $spelling = '';

        foreach ($tokens as $token) {
            if (\is_array($token) && \in_array($token[0], self::NAME_TOKENS, true)) {
                $spelling .= $token[1];
            }
        }

        return \strtolower($spelling);
    }
}
