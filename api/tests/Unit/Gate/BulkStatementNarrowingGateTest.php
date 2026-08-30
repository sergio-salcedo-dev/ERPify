<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\BulkStatementNarrowing;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A file that builds DQL bulk statements narrows exactly that many results through `AffectedRows::from()`.
 *
 * **The rule is a count, and positive.** `Doctrine\ORM\AbstractQuery::execute()` is declared `mixed`, so
 * every adapter whose port promises an `int` must narrow it, and seven reached independently for
 * `\is_int($affected) ? $affected : 0` — a fallback minting the exact value its callers read as evidence
 * that nothing needed erasing. Banning that token bans that token: `is_numeric()`, an `(int)` cast and a
 * `@phpstan-var int` all land in the same place and would pass a ban. Requiring each statement to be
 * accounted for by a narrowing is an obligation that has to be met rather than avoided.
 *
 * **Equality in both directions, because presence was measured insufficient.** A method that guards one
 * statement and fabricates a zero for a second satisfied a `str_contains` check — the archive-then-delete
 * and lock-then-delete shapes are the likeliest to arrive next, and the earlier gate was green over exactly
 * the defect it existed to refuse. One comparison closes both a missing narrowing and a spare one.
 *
 * **Nothing here is exempt, and that is what removed the parser.** The earlier version attributed each
 * statement to its enclosing method so a `void` one could be excused, and an adversarial pass measured six
 * defects in that attribution alone. The session adapter's bulk revocation discards its count and still
 * routes it through the guard: asserting that a bulk statement returned a count is meaningful even when
 * nobody reads the number, so the exemption bought nothing and cost a parser. Blind spots are enumerated on
 * {@see BulkStatementNarrowing}; the load-bearing one is that a DEAD guard call balances the count, which no
 * count can see and only review can.
 *
 * @internal
 */
#[CoversNothing]
final class BulkStatementNarrowingGateTest extends TestCase
{
    /**
     * The population this rule was written over: four adapters, eight statements. A floor rather than a pin,
     * so a fifth adapter is not a red — but a floor at all, because `assertNotEmpty` would stay green after
     * a sweep regression that found one file instead of four, over seven unguarded statements.
     */
    private const int KNOWN_FILES = 4;

    private const int KNOWN_STATEMENTS = 8;

    public function testEveryBulkStatementIsAccountedForByANarrowing(): void
    {
        $unbalanced = [];

        foreach (BulkStatementNarrowing::inApiSource() as $file => $counts) {
            if ($counts['statements'] !== $counts['narrowings']) {
                $unbalanced[] = \sprintf(
                    '%s builds %d DQL bulk statement(s) and narrows %d',
                    $file,
                    $counts['statements'],
                    $counts['narrowings'],
                );
            }
        }

        $this->assertSame(
            [],
            $unbalanced,
            'Every DQL DELETE/UPDATE must hand its result to AffectedRows::from(). Query::execute() is '
            . 'declared mixed, so a hand-rolled narrowing has to invent a value for the shapes it does not '
            . 'expect — and the value every one of them invented was 0, which the erasure evidence path '
            . 'reads as "there was nothing to erase". A spare narrowing fails too: the counts are the claim.',
        );
    }

    /**
     * The anti-vacuity half. Nothing above distinguishes a tree that obeys the rule from a sweep that
     * stopped matching, and a sweep that shrinks rather than empties is the way this gate would die quietly.
     */
    public function testTheSweepStillReachesTheAdaptersItGoverns(): void
    {
        $counted = BulkStatementNarrowing::inApiSource();
        $statements = \array_sum(\array_column($counted, 'statements'));

        $this->assertGreaterThanOrEqual(self::KNOWN_FILES, \count($counted), 'The sweep lost adapter files.');
        $this->assertGreaterThanOrEqual(
            self::KNOWN_STATEMENTS,
            $statements,
            'The sweep found fewer DQL bulk statements than the population this rule was written over. '
            . 'Either the adapters changed how they build statements, or the scanner stopped seeing them.',
        );
    }

    /**
     * Shapes the tree does not hold, so what the rule admits and refuses is measured rather than restated.
     * A gate whose only subject is the code it guards cannot tell "the rule holds" from "the scanner saw
     * nothing", and the containment is circular the moment the cases come out of the same sweep.
     *
     * @param array{statements: int, narrowings: int}|null $expected
     */
    #[DataProvider('provideTheScannerCountsEachShapeItMustDistinguishCases')]
    public function testTheScannerCountsEachShapeItMustDistinguish(?array $expected, string $source): void
    {
        $this->assertSame($expected, BulkStatementNarrowing::inSource($source));
    }

    /**
     * @return iterable<string, array{array{statements: int, narrowings: int}|null, string}>
     */
    public static function provideTheScannerCountsEachShapeItMustDistinguishCases(): iterable
    {
        yield from self::balancedShapes();

        yield from self::unbalancedShapes();

        yield from self::shapesOutsideTheUniverse();
    }

    /**
     * @return iterable<string, array{array{statements: int, narrowings: int}, string}>
     */
    private static function balancedShapes(): iterable
    {
        $balanced = ['statements' => 1, 'narrowings' => 1];

        yield 'guarded' => [$balanced, self::wrap(
            'return AffectedRows::from($this->qb()->delete(A::class, \'a\')->getQuery()->execute());',
        )];

        yield 'guarded through an FQCN' => [$balanced, self::wrap(
            'return ' . \Erpify\Shared\Persistence\Infrastructure\AffectedRows::class . '::from('
            . '$this->qb()->delete(A::class, \'a\')->getQuery()->execute());',
        )];

        // The discarded count still routes through the guard: no signature is exempt, which is what let the
        // rule drop method attribution entirely.
        yield 'guarded and discarded' => [$balanced, self::wrap(
            'AffectedRows::from($this->qb()->update(A::class, \'a\')->getQuery()->execute());',
        )];
    }

    /**
     * @return iterable<string, array{array{statements: int, narrowings: int}, string}>
     */
    private static function unbalancedShapes(): iterable
    {
        $missing = ['statements' => 1, 'narrowings' => 0];

        yield 'the original fallback' => [$missing, self::wrap(
            '$a = $this->qb()->delete(A::class, \'a\')->getQuery()->execute();'
            . ' return \is_int($a) ? $a : 0;',
        )];

        // Not a token ban: a cast reaches the same wrong place and is refused by the same arithmetic.
        yield 'a cast instead of the fallback' => [$missing, self::wrap(
            '$a = $this->qb()->delete(A::class, \'a\')->getQuery()->execute(); return (int) $a;',
        )];

        // `getResult()` is `return $this->execute(null, $hydrationMode);` and is already this repository's
        // spelling for reads, so naming the execution rather than the statement missed it entirely.
        yield 'run through getResult' => [$missing, self::wrap(
            '$a = $this->qb()->delete(A::class, \'a\')->getQuery()->getResult(); return (int) $a;',
        )];

        // The shape the earlier presence check was green over, and the likeliest one to arrive next.
        yield 'two statements, one narrowed' => [['statements' => 2, 'narrowings' => 1], self::wrap(
            '$kept = AffectedRows::from($this->qb()->update(A::class, \'a\')->getQuery()->execute());'
            . ' $gone = $this->qb()->delete(B::class, \'b\')->getQuery()->execute();'
            . ' return $kept + (\is_int($gone) ? $gone : 0);',
        )];

        // A method name that lexes to its own keyword token, and a by-reference declaration: both vanished
        // from the parser this rule replaced, and neither has anywhere to hide in a count.
        yield 'a reserved-word method name' => [$missing, \sprintf(
            "<?php\n\nfinal class G\n{\n    public function list(): int\n    {\n        %s\n    }\n}\n",
            '$a = $this->qb()->delete(A::class, \'a\')->getQuery()->execute(); return (int) $a;',
        )];

        yield 'a by-reference declaration' => [$missing, \sprintf(
            "<?php\n\nuse function is_int;\n\nfinal class G\n{\n"
            . "    public function &drop(): int\n    {\n        %s\n    }\n}\n",
            '$a = $this->qb()->delete(A::class, \'a\')->getQuery()->execute(); return is_int($a) ? $a : 0;',
        )];

        // Prose may not stand in for code, in either direction.
        yield 'the guard only quoted' => [$missing, self::wrap(
            '$a = $this->qb()->delete(A::class, \'a\')->getQuery()->execute();'
            . ' $this->log(\'AffectedRows::from( is not a call here\'); return (int) $a;',
        )];

        yield 'the guard only in a comment' => [$missing, self::wrap(
            '$a = $this->qb()->delete(A::class, \'a\')->getQuery()->execute();'
            . " // AffectedRows::from(\$a)\n        return (int) \$a;",
        )];
    }

    /**
     * A read builds no bulk statement, and a collaborator's `delete()` names a value rather than an entity.
     *
     * @return iterable<string, array{null, string}>
     */
    private static function shapesOutsideTheUniverse(): iterable
    {
        yield 'a read query' => [null, self::wrap(
            'return $this->qb()->select(\'a\')->getQuery()->execute();',
        )];

        yield 'a collaborator delete' => [null, self::wrap(
            '$this->storage->delete($id); return $this->qb()->select(\'a\')->getQuery()->getResult();',
        )];

        yield 'no query at all' => [null, self::wrap('$this->storage->delete($id);')];

        // A collaborator whose argument IS a class name, in a file that builds no query: the `::class` shape
        // alone would read it as a statement, and requiring the file to build a Query is what refuses it.
        yield 'a collaborator taking a class name' => [null, self::wrap('$this->cache->delete(A::class);')];
    }

    private static function wrap(string $body): string
    {
        return \sprintf(
            "<?php\n\nfinal class G\n{\n    public function run(): mixed\n    {\n        %s\n    }\n}\n",
            $body,
        );
    }
}
