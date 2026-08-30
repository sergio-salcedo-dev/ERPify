<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Counts, per file, the DQL bulk statements it builds and the times it narrows a result through the shared
 * guard. The rule the counts serve is equality, and everything about this class follows from that choice.
 *
 * **It reads counts rather than structure, and that is the correction rather than a shortcut.** The first
 * version of this gate attributed each statement to its enclosing named method by tokenising and
 * brace-matching, so it could exempt a `void` method. An adversarial pass measured six defects in that
 * parser alone — `use function is_int;` collapsed a whole class into one phantom record and hid every real
 * site in the file; a method named `list` or `match` lexes to its keyword token and vanished; `function &f()`
 * vanished, and both attempts to handle the `&` were unreachable code; a `"$a}"` literal miscounted braces;
 * a nested anonymous class was attributed to its enclosing method and a nested named function to the `void`
 * method around it. Every one was a property of the parser, not of the rule. Counting per file needs no
 * method boundary, so none of those shapes exists to get wrong.
 *
 * **What the counts must be equal, not merely present.** `\str_contains()` was the earlier check, and a
 * method that guards one statement and fabricates a zero for a second passed it — measured, and it is the
 * likeliest shape to arrive next (archive-then-delete, lock-then-delete). Equality refuses that in both
 * directions with one comparison.
 *
 * **The universe is DML, not execution.** Naming the execution (`->execute(`) was wrong twice over:
 * `AbstractQuery::getResult()` is `return $this->execute(null, $hydrationMode);` and is already this
 * repository's spelling for reads, so an eighth adapter written with it was invisible; and a SELECT run
 * through `->execute()` was demanded to reach a guard whose only effect on an array is a 500. A statement
 * built with the query builder's `delete()`/`update()` is the actual subject, and it is the subject whatever
 * spelling runs it.
 *
 * **What it cannot see**, and this list is the bound on a green:
 *   - a guard call that is DEAD — an unused closure or an unreachable branch naming it — which balances the
 *     count while narrowing nothing. No count and no text sweep can tell a reached call from a written one;
 *     review is the only control on that direction;
 *   - a statement whose DQL is a STRING — `createQuery('DELETE FROM …')`, or `delete('Foo\\Bar', 'f')`
 *     spelled without `::class` — because string contents are deliberately dropped: prose and literals must
 *     never stand in for code, and that cut has to hold in both directions. Zero such sites today;
 *   - a file that builds a statement here and narrows it in ANOTHER file: the counts are per file;
 *   - `AffectedRows` imported under an alias, which is a false RED — loud and diagnosable at the line. This
 *     rule fails toward noise where its predecessor failed toward silence;
 *   - whether the count is CORRECT. A statement missing a predicate, or one whose transaction rolls back
 *     after it, returns a perfectly well-typed count and passes.
 *
 * @internal test support
 */
final class BulkStatementNarrowing
{
    /** How a query builder is turned into a runnable query. A file with neither builds no DQL at all. */
    private const array BUILDERS = ['->getQuery()', '->createQuery('];

    /**
     * The two DQL statements that report an affected-row count, matched by the shape only the query
     * builder's own `delete()`/`update()` has: the entity they operate on, named as a class constant. A
     * collaborator's `->delete($id)` takes a value and is not one of these — matching the bare method name
     * made a repository that runs a read and calls a storage port's `delete()` a false red.
     */
    private const string STATEMENT = '~->(?:delete|update)\(\\\?[A-Za-z_][A-Za-z0-9_\\\]*::class~';

    /** The only sanctioned narrowing. Matched after the name form is flattened, so an FQCN call counts. */
    private const string GUARD = 'AffectedRows::from(';

    /**
     * Every file under `api/src` that builds a DQL bulk statement, with its two counts.
     *
     * @return array<string, array{statements: int, narrowings: int}> keyed by path relative to `api/src`
     */
    public static function inApiSource(): array
    {
        $root = ApiSourceFiles::root();
        $counted = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $path = $file->getPathname();
            $source = \file_get_contents($path);

            if (false === $source) {
                throw new RuntimeException(\sprintf('Could not read %s; the sweep would silently skip it.', $path));
            }

            $counts = self::inSource($source);

            if (null !== $counts) {
                $counted[\ltrim(\str_replace($root, '', $path), '/')] = $counts;
            }
        }

        return $counted;
    }

    /**
     * @return array{statements: int, narrowings: int}|null null when the source builds no DQL bulk statement
     */
    public static function inSource(string $source): ?array
    {
        $code = self::code($source);

        if (0 === self::occurrences($code, self::BUILDERS)) {
            return null;
        }

        $statements = \preg_match_all(self::STATEMENT, $code);

        // A pattern that fails to compile matches nothing, which would empty the sweep in the one direction
        // this rule exists to refuse — measured, by shipping exactly that: a `\\` collapsed by the single
        // quotes left the character class unterminated, and every file went silently out of the universe.
        if (false === $statements) {
            throw new RuntimeException('The bulk-statement pattern did not compile; the sweep would be empty.');
        }

        if (0 === $statements) {
            return null;
        }

        return ['statements' => $statements, 'narrowings' => \substr_count($code, self::GUARD)];
    }

    /**
     * @param list<string> $needles
     */
    private static function occurrences(string $code, array $needles): int
    {
        $total = 0;

        foreach ($needles as $needle) {
            $total += \substr_count($code, $needle);
        }

        return $total;
    }

    /**
     * The source with comments, whitespace and every string's CONTENT dropped. A statement or a guard named
     * in a docblock or quoted in a literal states an intention, and an intention must never be able to stand
     * in for the thing itself — measured in both directions, since a quoted guard name would otherwise
     * balance a file that narrows nothing.
     */
    private static function code(string $source): string
    {
        $dropped = [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (!\is_array($token)) {
                $code .= $token;

                continue;
            }

            if (!\in_array($token[0], $dropped, true)) {
                $code .= $token[1];
            }
        }

        return $code;
    }
}
