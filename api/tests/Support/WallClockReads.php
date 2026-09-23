<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Finds every place a PHP source reads the current instant without going through the injected
 * `Erpify\Shared\Clock\Domain\Clock` port — a second time source, whose instant can disagree with the one the
 * application layer read and handed inward.
 *
 * Read with `token_get_all`, never per line, so a call split across lines, a comment between the name and its
 * parenthesis, or a fully-qualified `\time()` is the same call. What counts as a read:
 *
 *   - `new DateTime[Immutable]` / `date_create[_immutable]()` with no argument, or whose first argument
 *     STARTS with a string literal that is not an absolute instant (`'now'`, `'-30 days'`, `'tomorrow'`,
 *     `'-' . $n . ' days'`). A first argument held in a variable is parsing a value, not reading the clock.
 *   - `new DatePoint` with no argument or a relative literal, since Symfony's `DatePoint` defaults to its
 *     global clock and so reaches past the port.
 *   - `time()`, `microtime()`, `mktime()`, `gmmktime()`, `gettimeofday()` always; `getdate()` and
 *     `localtime()` with no argument; `date()`, `gmdate()`, `idate()` and `strtotime()` with one.
 *   - `Symfony\Component\Clock\Clock::get()` and `Symfony\Component\Clock\now()`, the global clock reached
 *     statically rather than injected.
 *
 * **Blind spots, stated rather than implied.** A relative spec reached through a variable
 * (`new DateTimeImmutable($spec)`) or a constant, `hrtime()` (a monotonic duration, not an instant, and
 * deliberately not a finding), a call through a callable string, and a namespaced function that shadows a
 * global of the same name. Postgres's `now()` inside SQL is not PHP and is not read at all.
 */
final class WallClockReads
{
    private const array DATE_CLASSES = ['datetime', 'datetimeimmutable', 'datepoint'];

    private const array ALWAYS = ['time', 'microtime', 'mktime', 'gmmktime', 'gettimeofday'];

    private const array WITHOUT_ARGUMENTS = ['getdate', 'localtime'];

    private const array WITH_ONE_ARGUMENT = ['date', 'gmdate', 'idate', 'strtotime'];

    private const array CONSTRUCTOR_LIKE = ['date_create', 'date_create_immutable'];

    private const array INSIGNIFICANT = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    private const array NAME_TOKENS = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];

    private const array MEMBER_OR_DECLARATION = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_FUNCTION,
        T_NEW,
        T_CONST,
    ];

    /**
     * An absolute instant as a literal: an ISO-8601 date or a Unix timestamp. Anything else a date
     * constructor parses is resolved against "now".
     */
    private const string ABSOLUTE = '/^\s*(?:@-?\d|\d{4}-\d{2}-\d{2})/';

    /**
     * @return list<int> the line of each read, in source order
     */
    public static function inSource(string $source): array
    {
        $tokens = self::significant(\token_get_all($source));
        $symfonyClockImported = 1 === \preg_match('/^use\s+Symfony\\\Component\\\Clock\\\Clock\s*;/m', $source);
        $symfonyNowImported = 1 === \preg_match('/^use\s+function\s+Symfony\\\Component\\\Clock\\\now\s*;/m', $source);
        $lines = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token)) {
                continue;
            }

            $line = match (true) {
                T_NEW === $token[0] => self::constructorRead($tokens, $index),
                \in_array($token[0], self::NAME_TOKENS, true) => self::functionRead(
                    $tokens,
                    $token,
                    $index,
                    $symfonyClockImported,
                    $symfonyNowImported,
                ),
                default => null,
            };

            if (null !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function constructorRead(array $tokens, int $index): ?int
    {
        $class = $tokens[$index + 1] ?? null;

        if (!\is_array($class) || !\in_array(self::shortName($class[1]), self::DATE_CLASSES, true)) {
            return null;
        }

        if ('(' !== ($tokens[$index + 2] ?? null)) {
            return $class[2];
        }

        return self::readsNow(\array_slice($tokens, $index + 2)) ? $class[2] : null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @param array{int, string, int}              $name   the name token at `$index`
     */
    private static function functionRead(
        array $tokens,
        array $name,
        int $index,
        bool $symfonyClockImported,
        bool $symfonyNowImported,
    ): ?int {
        if ('(' !== ($tokens[$index + 1] ?? null)) {
            return null;
        }

        if (self::isStaticGetOnSymfonyClock($tokens, $name, $index, $symfonyClockImported)) {
            return $name[2];
        }

        $previous = $tokens[$index - 1] ?? null;

        if (\is_array($previous) && \in_array($previous[0], self::MEMBER_OR_DECLARATION, true)) {
            return null;
        }

        return self::isGlobalRead($name, \array_slice($tokens, $index + 1), $symfonyNowImported) ? $name[2] : null;
    }

    /**
     * @param array{int, string, int}              $name
     * @param list<array{int, string, int}|string> $fromParenthesis
     */
    private static function isGlobalRead(array $name, array $fromParenthesis, bool $symfonyNowImported): bool
    {
        $spelling = \strtolower($name[1]);

        if ('\symfony\component\clock\now' === $spelling || ('now' === $spelling && $symfonyNowImported)) {
            return true;
        }

        if (T_NAME_QUALIFIED === $name[0]) {
            return false;
        }

        $function = \ltrim($spelling, '\\');

        return match (true) {
            \in_array($function, self::ALWAYS, true) => true,
            \in_array($function, self::WITHOUT_ARGUMENTS, true) => 0 === self::argumentCount($fromParenthesis),
            \in_array($function, self::WITH_ONE_ARGUMENT, true) => 1 === self::argumentCount($fromParenthesis),
            \in_array($function, self::CONSTRUCTOR_LIKE, true) => self::readsNow($fromParenthesis),
            default => false,
        };
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @param array{int, string, int}              $name   the name token at `$index`
     */
    private static function isStaticGetOnSymfonyClock(
        array $tokens,
        array $name,
        int $index,
        bool $symfonyClockImported,
    ): bool {
        $operator = $tokens[$index - 1] ?? null;
        $class = $tokens[$index - 2] ?? null;

        if ('get' !== \strtolower($name[1]) || !\is_array($operator) || T_DOUBLE_COLON !== $operator[0]) {
            return false;
        }

        if (!\is_array($class)) {
            return false;
        }

        $spelling = \ltrim(\strtolower($class[1]), '\\');

        return 'symfony\component\clock\clock' === $spelling || ('clock' === $spelling && $symfonyClockImported);
    }

    /**
     * True when the argument list starting at `(` carries no argument, or a first argument whose first token
     * is a relative string literal.
     *
     * @param list<array{int, string, int}|string> $fromParenthesis
     */
    private static function readsNow(array $fromParenthesis): bool
    {
        $first = ArgumentTokens::argumentAt(ArgumentTokens::insideBrackets($fromParenthesis), 1);

        if (null === $first || [] === $first) {
            return true;
        }

        $head = $first[0];

        if (!\is_array($head) || T_CONSTANT_ENCAPSED_STRING !== $head[0]) {
            return false;
        }

        return 1 !== \preg_match(self::ABSOLUTE, \substr($head[1], 1, -1));
    }

    /**
     * @param list<array{int, string, int}|string> $fromParenthesis
     */
    private static function argumentCount(array $fromParenthesis): int
    {
        $inside = ArgumentTokens::insideBrackets($fromParenthesis);

        if ([] === $inside) {
            return 0;
        }

        return 1 + \iterator_count(ArgumentTokens::topLevelTokensOf($inside, ','));
    }

    private static function shortName(string $name): string
    {
        $segments = \explode('\\', \strtolower($name));

        return (string) \end($segments);
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    private static function significant(array $tokens): array
    {
        return \array_values(\array_filter(
            $tokens,
            static fn (array|string $token): bool => !\is_array($token)
                || !\in_array($token[0], self::INSIGNIFICANT, true),
        ));
    }
}
