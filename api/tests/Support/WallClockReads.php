<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Finds every place a PHP source reads the current instant without going through the injected
 * `Erpify\Shared\Clock\Domain\Clock` port — a second time source, whose instant can disagree with the one the
 * application layer read and handed inward.
 *
 * Read with `token_get_all`, never per line, so a call split across lines, a comment between the name and its
 * parenthesis, a fully-qualified `\time()` and a name imported under an alias ({@see ImportAliases}) are the
 * same call. What counts as a read:
 *
 *   - `new DateTime[Immutable]` / `new DatePoint` / `date_create[_immutable]()` with no `datetime` argument —
 *     positionally or by name, so `new DateTimeImmutable(timezone: $zone)` is one — or whose `datetime`
 *     argument STARTS with a string that is not an absolute instant: a literal (`'now'`, `'-30 days'`,
 *     `'-' . $n . ' days'`), an interpolated string (`"-{$n} days"`) or a heredoc. A value held in a variable is
 *     parsed, not read.
 *   - `new NativeClock()` / `new MonotonicClock()` from `symfony/clock`, and `Symfony\Component\Clock\Clock::get()`
 *     or `Symfony\Component\Clock\now()` — the global clock reached statically rather than injected.
 *   - `time()`, `microtime()`, `mktime()`, `gmmktime()`, `gettimeofday()` always; `getdate()` and
 *     `localtime()` with no argument; `date()`, `gmdate()`, `idate()` and `strtotime()` with one; and
 *     `$_SERVER['REQUEST_TIME']` / `['REQUEST_TIME_FLOAT']`.
 *
 * An absolute instant is an ISO-8601 date (`2026-01-01…`) or a Unix timestamp (`'@' . $ts`). Every other
 * literal is taken as relative, so `'Jan 1 2026'` or `strtotime('2026-01-01')` is reported although it names a
 * fixed instant: the remedy is to spell it in ISO form or build it from a value, which this errs towards on
 * purpose rather than guessing which of PHP's formats consult the clock.
 *
 * **Blind spots, stated rather than implied.** A relative spec reached through a variable, a constant or a
 * call (`new DateTimeImmutable($spec)`, `new DateTimeImmutable(\sprintf('-%d days', $n))`); an interpolated
 * string that starts with the interpolation; `DateTimeImmutable::createFromFormat()` with a format lacking
 * `!` or `|`, which fills the fields it omits from the wall clock; `date($format, null)`; `new (Foo::class)()`;
 * a call through a callable string; `hrtime()`, deliberately, since it measures a duration rather than naming
 * an instant; and a namespaced function shadowing a global of the same name. Postgres's `now()` inside SQL is
 * not PHP and is not read at all.
 */
final class WallClockReads
{
    private const array DATE_CLASSES = ['datetime', 'datetimeimmutable', 'symfony\component\clock\datepoint'];

    private const array CLOCK_CLASSES = [
        'symfony\component\clock\nativeclock',
        'symfony\component\clock\monotonicclock',
    ];

    private const string GLOBAL_CLOCK = 'symfony\component\clock\clock';

    private const string GLOBAL_NOW = 'symfony\component\clock\now';

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
     * @return list<int> the line of each read, in source order
     */
    public static function inSource(string $source): array
    {
        $tokens = self::significant(\token_get_all($source));
        $aliases = ImportAliases::of($tokens);
        $lines = [];

        foreach (\array_keys($tokens) as $index) {
            $line = self::readAt($tokens, $index, $aliases);

            if (null !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<array{int, string, int}|string>                                    $tokens
     * @param array{classes: array<string, string>, functions: array<string, string>} $aliases
     */
    private static function readAt(array $tokens, int $index, array $aliases): ?int
    {
        $token = $tokens[$index] ?? null;

        if (!\is_array($token)) {
            return null;
        }

        return match (true) {
            T_NEW === $token[0] => self::constructorRead($tokens, $index, $aliases['classes']),
            T_VARIABLE === $token[0] => self::requestTimeRead($tokens, $index, $token),
            \in_array($token[0], self::NAME_TOKENS, true) => self::functionRead($tokens, $index, $token, $aliases),
            default => null,
        };
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @param array<string, string>                $classAliases
     */
    private static function constructorRead(array $tokens, int $index, array $classAliases): ?int
    {
        $class = $tokens[$index + 1] ?? null;

        if (!\is_array($class) || !\in_array($class[0], self::NAME_TOKENS, true)) {
            return null;
        }

        $resolved = self::resolveClass($class[1], $classAliases);

        if (\in_array($resolved, self::CLOCK_CLASSES, true)) {
            return $class[2];
        }

        if (!\in_array($resolved, self::DATE_CLASSES, true)) {
            return null;
        }

        if ('(' !== ($tokens[$index + 2] ?? null)) {
            return $class[2];
        }

        return DateTimeArguments::readsNow(\array_slice($tokens, $index + 2)) ? $class[2] : null;
    }

    /**
     * @param list<array{int, string, int}|string>                                    $tokens
     * @param array{int, string, int}                                                 $name    the token at `$index`
     * @param array{classes: array<string, string>, functions: array<string, string>} $aliases
     */
    private static function functionRead(array $tokens, int $index, array $name, array $aliases): ?int
    {
        if ('(' !== ($tokens[$index + 1] ?? null)) {
            return null;
        }

        $previous = $tokens[$index - 1] ?? null;
        $isMember = \is_array($previous) && \in_array($previous[0], self::MEMBER_OR_DECLARATION, true);

        if ($isMember) {
            return self::isStaticGetOnGlobalClock($tokens, $index, $name, $aliases['classes']) ? $name[2] : null;
        }

        $fromParenthesis = \array_slice($tokens, $index + 1);

        return self::isGlobalRead($name, $fromParenthesis, $aliases['functions']) ? $name[2] : null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @param array{int, string, int}              $name
     * @param array<string, string>                $classAliases
     */
    private static function isStaticGetOnGlobalClock(array $tokens, int $index, array $name, array $classAliases): bool
    {
        $operator = $tokens[$index - 1] ?? null;
        $class = $tokens[$index - 2] ?? null;

        if ('get' !== \strtolower($name[1]) || !\is_array($operator) || T_DOUBLE_COLON !== $operator[0]) {
            return false;
        }

        return \is_array($class) && self::GLOBAL_CLOCK === self::resolveClass($class[1], $classAliases);
    }

    /**
     * @param array{int, string, int}              $name
     * @param list<array{int, string, int}|string> $fromParenthesis
     * @param array<string, string>                $functionAliases
     */
    private static function isGlobalRead(array $name, array $fromParenthesis, array $functionAliases): bool
    {
        $spelling = \strtolower($name[1]);
        $function = $functionAliases[$spelling] ?? \ltrim($spelling, '\\');

        if (self::GLOBAL_NOW === $function) {
            return true;
        }

        if (T_NAME_QUALIFIED === $name[0] && !\array_key_exists($spelling, $functionAliases)) {
            return false;
        }

        $arguments = DateTimeArguments::argumentCount($fromParenthesis);

        return match (true) {
            \in_array($function, self::ALWAYS, true) => true,
            \in_array($function, self::WITHOUT_ARGUMENTS, true) => 0 === $arguments,
            \in_array($function, self::WITH_ONE_ARGUMENT, true) => 1 === $arguments,
            \in_array($function, self::CONSTRUCTOR_LIKE, true) => DateTimeArguments::readsNow($fromParenthesis),
            default => false,
        };
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @param array{int, string, int}              $variable
     */
    private static function requestTimeRead(array $tokens, int $index, array $variable): ?int
    {
        $key = $tokens[$index + 2] ?? null;

        if ('$_SERVER' !== $variable[1] || '[' !== ($tokens[$index + 1] ?? null) || !\is_array($key)) {
            return null;
        }

        return T_CONSTANT_ENCAPSED_STRING === $key[0] && \str_starts_with(\trim($key[1], '\'"'), 'REQUEST_TIME')
            ? $variable[2]
            : null;
    }

    /**
     * @param array<string, string> $classAliases
     */
    private static function resolveClass(string $spelling, array $classAliases): string
    {
        $name = \strtolower($spelling);

        if (\str_starts_with($name, '\\')) {
            return \ltrim($name, '\\');
        }

        $segments = \explode('\\', $name, 2);
        $imported = $classAliases[$segments[0]] ?? null;

        if (null === $imported) {
            return $name;
        }

        return isset($segments[1]) ? $imported . '\\' . $segments[1] : $imported;
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
