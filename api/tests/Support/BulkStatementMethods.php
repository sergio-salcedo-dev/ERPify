<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Finds every named method in PHP source whose body runs an ORM statement, and reports whether it narrows
 * that statement's result through the shared guard.
 *
 * **The universe is derived, never enumerated, and that is the whole reason this exists rather than a token
 * ban.** The defect it guards is a narrowing that fabricates a count — `\is_int($affected) ? $affected : 0`
 * over the `mixed` that `Doctrine\ORM\AbstractQuery::execute()` returns. Refusing that spelling refuses one
 * spelling: `is_numeric()`, an `(int)` cast and a `@phpstan-var int` all reach the same wrong place and would
 * pass. Requiring instead that the result REACH `AffectedRows::from()` is a positive obligation, so anything
 * that does not reach it fails however it is written.
 *
 * **Attribution is by enclosing named method, deliberately.** The adapters wrap the statement in an arrow
 * function (`convertingStoreFailure(fn (): mixed => …->execute())`), so the call lives in a closure while the
 * obligation lives on the method that returns the value. The scanner therefore never descends into a nested
 * `function`/`fn` as a declaration of its own — it brace-matches a named method and takes everything inside.
 *
 * **What it cannot see**, and the list is the bound on a green:
 *   - a query whose `Query` object is obtained in ONE method and executed in ANOTHER: the pair
 *     (`->getQuery()`/`->createQuery(` plus `->execute(`) is read within a single body, because a bare
 *     `->execute(` sweep reports every use case in the tree — `execute()` is this codebase's invocation
 *     convention, and five of them came back on the first run;
 *   - a statement reached through a DBAL `executeStatement()`, which already returns `int` and is out of
 *     scope by construction — but a future ORM spelling for either half would be invisible;
 *   - a method that narrows correctly and then returns something else entirely: reaching the guard is the
 *     obligation, using its answer is not;
 *   - a `void` method whose closure hands a fabricated count somewhere else — the signature is the
 *     discriminator, and a `void` method returns nothing to fabricate;
 *   - whether the count is CORRECT. A statement missing a predicate returns a well-typed `int` and passes.
 *
 * @internal test support
 */
final class BulkStatementMethods
{
    /**
     * How an ORM `Query` is obtained. Both spellings, though only the builder's is reached today: the pair is
     * the API surface, and the site this gate exists for is the one nobody has written.
     */
    private const array QUERY_SOURCES = ['->getQuery()', '->createQuery('];

    /** How it is run. `?->execute(` contains it, so the nullsafe form needs no second spelling. */
    private const string EXECUTION = '->execute(';

    /** The only sanctioned narrowing. Matched after the name form is flattened, so an FQCN call counts. */
    private const string GUARD = 'AffectedRows::from(';

    /**
     * Every method in `api/src` that runs a statement, keyed by nothing — the caller filters.
     *
     * @return list<BulkStatementMethod>
     */
    public static function inApiSource(): array
    {
        $root = ApiSourceFiles::root();
        $methods = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $path = $file->getPathname();
            $source = \file_get_contents($path);

            if (false === $source) {
                continue;
            }

            $relative = \ltrim(\str_replace($root, '', $path), '/');

            foreach (self::fromSource($source, $relative) as $method) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * @return list<BulkStatementMethod>
     */
    public static function fromSource(string $source, string $file): array
    {
        $tokens = \token_get_all(PhpSource::withoutComments($source));
        $methods = [];
        $index = 0;

        while (isset($tokens[$index])) {
            $token = $tokens[$index];

            if (!\is_array($token) || T_FUNCTION !== $token[0]) {
                ++$index;

                continue;
            }

            $name = self::nameAt($tokens, $index + 1);

            if (null === $name) {
                ++$index;

                continue;
            }

            $open = self::nextOf($tokens, $index, '(');
            $close = self::matching($tokens, $open, '(', ')');
            $body = self::nextOf($tokens, $close, '{', ';');

            if (null === $body || ';' === self::text($tokens[$body] ?? '')) {
                $index = null === $body ? $index + 1 : $body + 1;

                continue;
            }

            $end = self::matching($tokens, $body, '{', '}');
            $code = self::code(\array_slice($tokens, $body, $end - $body + 1));

            if (self::runsAQuery($code)) {
                $methods[] = new BulkStatementMethod(
                    $file,
                    $name,
                    self::returnType($tokens, $close + 1, $body),
                    \str_contains($code, self::GUARD),
                );
            }

            $index = $end + 1;
        }

        return $methods;
    }

    /**
     * The declared name, or null when the `function` keyword opens a closure — the one shape whose body must
     * stay attributed to the method enclosing it rather than becoming a declaration of its own.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param int<0, max>                                   $index
     */
    private static function nameAt(array $tokens, int $index): ?string
    {
        for ($i = $index; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];

            $skippable = [T_WHITESPACE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG];

            if (\is_array($token) && \in_array($token[0], $skippable, true)) {
                continue;
            }

            if (\is_string($token) && '&' === $token) {
                continue;
            }

            return \is_array($token) && T_STRING === $token[0] ? $token[1] : null;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param int<0, max>                                   $from
     *
     * @return int<0, max>|null
     */
    private static function nextOf(array $tokens, int $from, string ...$needles): ?int
    {
        for ($i = $from; isset($tokens[$i]); ++$i) {
            if (\in_array(self::text($tokens[$i]), $needles, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Depth-matched close for the delimiter at `$from`. String interpolation opens a brace through its own
     * token types (`{$`, `${`) and closes it with a plain `}`, so both count or the match runs off the end.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param int<0, max>|null                              $from
     *
     * @return int<0, max>
     */
    private static function matching(array $tokens, ?int $from, string $open, string $close): int
    {
        if (null === $from) {
            return \max(0, \count($tokens) - 1);
        }

        $depth = 0;

        for ($i = $from; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];
            $text = self::text($token);
            $interpolates = \is_array($token)
                && \in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);

            if ($text === $open || ('{' === $open && $interpolates)) {
                ++$depth;
            } elseif ($text === $close) {
                --$depth;

                if (0 === $depth) {
                    return $i;
                }
            }
        }

        return \max(0, \count($tokens) - 1);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param int<0, max>                                   $from
     * @param int<0, max>                                   $to
     */
    private static function returnType(array $tokens, int $from, int $to): string
    {
        $declared = \ltrim(self::code(\array_slice($tokens, $from, $to - $from)), ':');

        return \trim($declared);
    }

    /**
     * The tokens as source with whitespace and every string's CONTENT dropped: a `->execute(` inside a
     * literal states nothing, and a gate that reads it lets prose stand in for code.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function code(array $tokens): string
    {
        $code = '';

        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                $code .= $token;

                continue;
            }

            if (\in_array($token[0], [T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }

    /**
     * A method runs an ORM query when it both OBTAINS one and executes something. Requiring the pair is what
     * separates this from `$this->useCase->execute($id)`, the invocation convention every use case here
     * follows — five of which a bare `->execute(` sweep reported, measured. The two need not be one chain:
     * a `Query` parked in a variable and run on the next line is the same obligation, and reading only the
     * fluent spelling would miss it silently, which is the direction this whole rule exists to refuse.
     */
    private static function runsAQuery(string $code): bool
    {
        if (!\str_contains($code, self::EXECUTION)) {
            return false;
        }

        return \array_any(self::QUERY_SOURCES, static fn (string $source): bool => \str_contains($code, $source));
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
