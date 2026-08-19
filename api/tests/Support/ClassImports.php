<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Single source for reading the class names a PHP source file imports with `use`.
 *
 * Lexing rather than line matching, because the shapes below are one and the same statement to PHP
 * and five of them were measured passing a line-oriented matcher: a grouped import
 * (`use Doctrine\ORM\{A, B};`), a nested group, a statement whose `;` sits on the next line, two
 * statements sharing one line, and a comment in front of the keyword. An alias and a leading `\` are
 * handled here too. `use function` / `use const` import no class and are skipped, at the statement and
 * at the group-element level; a closure's `use (...)` imports no name either and terminates at its
 * opening parenthesis, so the closure body is never read as part of a statement.
 *
 * Read only: each caller layers its own matching on top.
 *
 * @internal test support
 */
final class ClassImports
{
    /** The token types a namespaced name is lexed into, plus the separator a group prefix ends on. */
    private const array NAME_TOKENS = [
        T_STRING,
        T_NS_SEPARATOR,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
    ];

    /**
     * Every class name a `use` import brings into scope, without its leading separator and paired with
     * the 1-based line its name starts on.
     *
     * @return list<array{name: string, line: int}>
     */
    public static function of(string $source): array
    {
        $names = [];

        foreach (self::useStatements(\token_get_all($source)) as $statement) {
            foreach (self::namesInStatement($statement) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The token slice of every `use`, from just past the keyword to its terminator. A closure's
     * `use (...)` ends at the opening parenthesis: it imports no name, and running on to the next `;`
     * would read the closure body as part of the statement.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<list<array{int, string, int}|string>>
     */
    private static function useStatements(array $tokens): array
    {
        $statements = [];
        $current = null;

        foreach ($tokens as $token) {
            if (null === $current) {
                $current = self::isKeyword($token, T_USE) ? [] : null;

                continue;
            }

            if (';' === $token || '(' === $token) {
                $statements[] = $current;
                $current = null;

                continue;
            }

            $current[] = $token;
        }

        return $statements;
    }

    /**
     * @param list<array{int, string, int}|string> $statement
     *
     * @return list<array{name: string, line: int}>
     */
    private static function namesInStatement(array $statement): array
    {
        [$prefix, $elements] = self::splitImport($statement);

        if (null === $prefix) {
            return [];
        }

        $names = [];

        foreach ($elements as $element) {
            $name = self::elementName($element);

            if (null !== $name) {
                $names[] = ['name' => \ltrim($prefix . $name['name'], '\\'), 'line' => $name['line']];
            }
        }

        return $names;
    }

    /**
     * Splits a statement into the prefix every element hangs off and the elements themselves: a group
     * import contributes the part before its brace, a plain one contributes nothing. A `use function`
     * / `use const` outside the brace covers the whole statement, so it yields a null prefix.
     *
     * @param list<array{int, string, int}|string> $statement
     *
     * @return array{0: ?string, 1: list<list<array{int, string, int}|string>>}
     */
    private static function splitImport(array $statement): array
    {
        $opening = self::indexOfLiteral($statement, '{');

        if (null === $opening) {
            return [self::importsSymbol($statement) ? null : '', self::splitOnCommas($statement)];
        }

        $head = \array_slice($statement, 0, $opening);

        return [
            self::importsSymbol($head) ? null : self::joinNameTokens($head),
            self::splitOnCommas(\array_slice($statement, $opening + 1)),
        ];
    }

    /**
     * The name one import element declares, or null when it imports a function or a constant instead
     * of a class. Everything from `as` on is the local alias, not part of the name.
     *
     * @param list<array{int, string, int}|string> $element
     *
     * @return array{name: string, line: int}|null
     */
    private static function elementName(array $element): ?array
    {
        if (self::importsSymbol($element)) {
            return null;
        }

        $name = '';
        $line = 0;

        foreach ($element as $token) {
            if (self::isKeyword($token, T_AS)) {
                break;
            }

            if (self::isNameToken($token)) {
                $line = '' === $name ? $token[2] : $line;
                $name .= $token[1];
            }
        }

        return '' === $name ? null : ['name' => $name, 'line' => $line];
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<list<array{int, string, int}|string>>
     */
    private static function splitOnCommas(array $tokens): array
    {
        $elements = [];
        $current = [];

        foreach ($tokens as $token) {
            if (',' === $token) {
                $elements[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        $elements[] = $current;

        return $elements;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function indexOfLiteral(array $tokens, string $literal): ?int
    {
        foreach ($tokens as $index => $token) {
            if ($literal === $token) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function importsSymbol(array $tokens): bool
    {
        return \array_any(
            $tokens,
            static fn (array|string $token): bool => self::isKeyword($token, T_FUNCTION)
                || self::isKeyword($token, T_CONST),
        );
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function joinNameTokens(array $tokens): string
    {
        $name = '';

        foreach ($tokens as $token) {
            if (self::isNameToken($token)) {
                $name .= $token[1];
            }
        }

        return $name;
    }

    /**
     * @param array{int, string, int}|string $token
     *
     * @phpstan-assert-if-true array{int, string, int} $token
     */
    private static function isNameToken(array|string $token): bool
    {
        return \is_array($token) && \in_array($token[0], self::NAME_TOKENS, true);
    }

    /** @param array{int, string, int}|string $token */
    private static function isKeyword(array|string $token, int $type): bool
    {
        return \is_array($token) && $type === $token[0];
    }
}
