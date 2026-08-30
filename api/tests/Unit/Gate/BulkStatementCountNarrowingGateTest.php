<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\BulkStatementMethod;
use Erpify\Tests\Support\BulkStatementMethods;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A method that runs an ORM statement and hands its result back narrows that result through
 * `AffectedRows::from()`, or it does not ship.
 *
 * **The rule is positive because the defect it answers had seven spellings of one mistake and room for
 * more.** `Doctrine\ORM\AbstractQuery::execute()` is declared `mixed`, so every adapter whose port promises
 * an `int` must narrow it, and each of the seven reached independently for
 * `\is_int($affected) ? $affected : 0` — a fallback minting the exact value its callers read as evidence
 * that nothing needed erasing. Banning that token bans that token: `is_numeric()`, an `(int)` cast and a
 * `@phpstan-var int` all land in the same place and would pass a ban. Requiring the result to REACH the
 * guard is an obligation nothing can be written around, only met.
 *
 * **A `void` method is out of the universe by signature, not by exemption.** It returns no count, so there
 * is none to fabricate — which is why the session adapter's bulk revocation (`bulkRevokeActive()`, the one
 * statement here nobody counts) runs a statement and owes nothing here. No allowlist exists, and none should: an
 * exemption list is how the eighth site gets written.
 *
 * **The grid is generated, never filtered from the tree.** A gate whose only subject is the code it guards
 * cannot tell "the rule holds" from "the scanner saw nothing", and the containment is circular the moment
 * the fixtures come from the same sweep. {@see testTheScannerClassifiesEachShapeItMustDistinguish} feeds it
 * shapes the tree does not contain — a statement inside a string literal, a closure nested in a `void`
 * method, a bodiless interface declaration — and asserts the classification of each.
 *
 * **What a green does not prove** is enumerated on {@see BulkStatementMethods}; the load-bearing one is that
 * it never judges the count. A statement missing a predicate returns a perfectly well-typed `int`.
 *
 * @internal
 */
#[CoversNothing]
final class BulkStatementCountNarrowingGateTest extends TestCase
{
    public function testEveryStatementResultHandedToACallerIsNarrowedThroughTheGuard(): void
    {
        $unguarded = \array_filter(
            BulkStatementMethods::inApiSource(),
            static fn (BulkStatementMethod $method): bool => $method->yieldsItsResult()
                && !$method->narrowsThroughGuard,
        );

        $this->assertSame(
            [],
            \array_map(static fn (BulkStatementMethod $m): string => $m->describe(), \array_values($unguarded)),
            'These methods return an ORM statement result without narrowing it through AffectedRows::from(). '
            . 'Query::execute() is declared mixed, so a hand-rolled narrowing has to invent a value for the '
            . 'shapes it does not expect — and the value every one of them invented was 0, which the erasure '
            . 'evidence path reads as "there was nothing to erase".',
        );
    }

    /**
     * The anti-vacuity half. Nothing in the assertion above distinguishes a tree that obeys the rule from a
     * scanner that matched no file at all, and the second is the way this gate would die silently.
     */
    public function testTheSweepStillReachesTheAdaptersItGoverns(): void
    {
        $governed = \array_filter(
            BulkStatementMethods::inApiSource(),
            static fn (BulkStatementMethod $method): bool => $method->yieldsItsResult(),
        );

        $this->assertNotEmpty(
            $governed,
            'The sweep found no method in api/src returning an ORM statement result. Either every adapter '
            . 'changed how it runs statements, or the scanner stopped seeing them — and a gate that governs '
            . 'nothing passes for ever.',
        );
    }

    public function testTheScannerClassifiesEachShapeItMustDistinguish(): void
    {
        $found = BulkStatementMethods::fromSource($this->grid(), 'Grid.php');

        $classified = [];

        foreach ($found as $method) {
            $classified[$method->name] = \sprintf(
                '%s|%s',
                $method->yieldsItsResult() ? 'governed' : 'exempt',
                $method->narrowsThroughGuard ? 'guarded' : 'unguarded',
            );
        }

        $this->assertSame(
            [
                'guarded' => 'governed|guarded',
                'unguarded' => 'governed|unguarded',
                'guardedThroughAnFqcn' => 'governed|guarded',
                'behindAnArrowFunction' => 'governed|guarded',
                'heldInAVariable' => 'governed|unguarded',
                'quotingTheGuard' => 'governed|unguarded',
                'discardingTheCount' => 'exempt|unguarded',
                'wrappingAClosure' => 'exempt|unguarded',
            ],
            $classified,
            'The scanner must govern what returns a result, exempt what returns none, attribute a nested '
            . 'closure to the method enclosing it, and stay blind to a statement that is only quoted or '
            . 'only declared.',
        );
    }

    /**
     * Shapes the tree does not hold, so the classification is measured rather than restated. Three groups,
     * because that is what the assertion above distinguishes: what the rule governs, what its signature
     * exempts, and what it must not see at all.
     */
    private function grid(): string
    {
        return \sprintf(
            "<?php\n\ninterface Port\n{\n    public function declaredOnly(): int;\n}\n\n"
            . "final class Grid\n{\n%s\n%s\n%s\n}\n",
            $this->governedShapes(),
            $this->exemptShapes(),
            $this->invisibleShapes(),
        );
    }

    /**
     * Every way a method can hand a statement result back. `guardedThroughAnFqcn` and `behindAnArrowFunction`
     * are how the adapters actually spell it; `heldInAVariable` is the two-statement form a fluent-chain
     * detector would miss while looking like it had checked; `quotingTheGuard` names the guard in a string
     * without calling it, and must stay `unguarded` or prose stands in for the narrowing.
     */
    private function governedShapes(): string
    {
        return <<<'SHAPES'
                public function guarded(): int
                {
                    $affected = $this->qb()->getQuery()->execute();

                    return AffectedRows::from($affected);
                }

                public function unguarded(): int
                {
                    $affected = $this->qb()->getQuery()->execute();

                    return \is_int($affected) ? $affected : 0;
                }

                public function guardedThroughAnFqcn(): int
                {
                    return \Erpify\Shared\Persistence\Infrastructure\AffectedRows::from(
                        $this->qb()->getQuery()->execute(),
                    );
                }

                public function behindAnArrowFunction(): int
                {
                    return AffectedRows::from($this->wrap(fn (): mixed => $this->qb()->getQuery()->execute()));
                }

                public function heldInAVariable(): int
                {
                    $query = $this->qb()->getQuery();
                    $affected = $query->execute();

                    return (int) $affected;
                }

                public function quotingTheGuard(): int
                {
                    $affected = $this->qb()->getQuery()->execute();
                    $this->log('AffectedRows::from( is not a call here either');

                    return (int) $affected;
                }
            SHAPES;
    }

    /**
     * A signature that returns no count has none to fabricate. `wrappingAClosure` also proves the scanner
     * never treats a nested closure as a declaration of its own — the statement inside it belongs to the
     * `void` method enclosing it, which is how the session adapter's bulk revocation is written.
     */
    private function exemptShapes(): string
    {
        return <<<'SHAPES'
                public function discardingTheCount(): void
                {
                    $this->qb()->getQuery()->execute();
                }

                public function wrappingAClosure(): void
                {
                    $this->wrap(function (): mixed {
                        return $this->qb()->getQuery()->execute();
                    });
                }
            SHAPES;
    }

    /**
     * None of these may appear in the sweep at all. `throughAUseCase` is the false positive a bare
     * `->execute(` detector produced five times over on this tree — `execute()` is this codebase's use-case
     * invocation convention — and the other two are why the scanner reads neither comments nor the CONTENT
     * of string literals. The bodiless `declaredOnly` on the interface above would derail a brace-matcher
     * reading its `;` as a body.
     */
    private function invisibleShapes(): string
    {
        return <<<'SHAPES'
                public function throughAUseCase(): int
                {
                    return $this->useCase->execute($this->id);
                }

                public function quotingTheStatement(): int
                {
                    $this->qb()->getQuery()->getSingleScalarResult();

                    return \strlen('->execute( is not a call here');
                }

                public function commentedOnly(): int
                {
                    // $this->qb()->getQuery()->execute();
                    return 0;
                }
            SHAPES;
    }
}
