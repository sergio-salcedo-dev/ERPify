<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over `make/db.mk`: loading fixtures must replay the projections afterwards.
 *
 * Seeding is three collaborating pieces and only the third is visible in a `make` recipe.
 * `EventBackbonePurger` resets the raw-DBAL tables the ORM purge cannot see;
 * `RecordSeededDomainEventsProcessor` appends the domain events the seeded aggregates recorded — and
 * appends only, deliberately, so nothing is dispatched. Projection catch-up is triggered by message
 * DELIVERY (`RunProjectionsOnDomainEvent` is a `#[AsMessageHandler]`), so with nothing dispatched
 * nothing catches up: without the replay the log is seeded and every read model still holds its
 * pre-seed value. That is not a degraded state, it is the original bug restored — a banks list header
 * reading "0 banks total" above 31 seeded rows, with the projection faithfully reporting a log it had
 * never been replayed over.
 *
 * The line is therefore load-bearing and looks like tidy-up, which is the combination worth gating:
 * deleting it turns nothing else in the suite red. The processor and the purger each have their own
 * tests; nothing else asserts that the seed pipeline is composed.
 *
 * Order is asserted, not just presence: a replay that runs BEFORE the load is replaying the PREVIOUS
 * run's log, and the load's purge then truncates the read model it just rebuilt — so the seed lands
 * with no replay behind it and the total reads zero, indistinguishable from the bug at every later reader.
 *
 * What a green proves: the recipe still names both commands, in order, in the same target. What it
 * does not prove: that either command succeeds, that the processor is ENROLLED (autoconfiguration
 * could be switched off and every assertion here stays green), or that any projection ends up holding
 * the right number — the end-to-end composition is measured by running it, and no gate replaces that.
 * What the purger does when it runs is `EventBackbonePurgerTest`'s subject, not this one's.
 *
 * @internal
 */
#[CoversNothing]
final class SeededProjectionRebuildGateTest extends TestCase
{
    private const string MAKEFILE = 'make/db.mk';

    private const string TARGET = 'db.load.fixtures:';

    private const string LOAD = 'hautelook:fixtures:load';

    private const string REPLAY = 'event:projection:rebuild --all';

    #[Test]
    public function theFixtureLoadTargetReplaysEveryProjectionAfterLoading(): void
    {
        $recipe = $this->recipe();

        $load = \mb_strpos($recipe, self::LOAD);
        $replay = \mb_strpos($recipe, self::REPLAY);

        $this->assertIsInt($load, \sprintf('`%s` no longer runs `%s`.', self::TARGET, self::LOAD));
        $this->assertIsInt($replay, \sprintf(
            '`%s` must run `%s` after loading: the seeded event log is appended without dispatching, '
            . 'so nothing triggers projection catch-up and every read model keeps its pre-seed value.',
            self::TARGET,
            self::REPLAY,
        ));
        $this->assertGreaterThan($load, $replay, \sprintf(
            '`%s` must run AFTER `%s` — replaying first rebuilds from the previous run\'s log, and the load\'s '
            . 'purge then truncates the read model it just rebuilt.',
            self::REPLAY,
            self::LOAD,
        ));
    }

    /**
     * The `db.load.fixtures` recipe alone — its own indented lines, minus the ones `sh` would treat as
     * comments. Scoping matters: `event:projection:rebuild` appearing anywhere else in the file would
     * otherwise satisfy the assertions above. The scan continues past the end of the recipe so a second
     * declaration of the same target is counted rather than missed.
     */
    private function recipe(): string
    {
        // Fails rather than skips when the root bind mount is gone: `make/db.mk` sits outside the
        // `api/` build context, and a gate that quietly passes when it cannot see its subject is worse
        // than no gate.
        $root = RepositoryRoot::path();
        $this->assertIsString($root, \sprintf(
            'The repository root is unreachable, so %s cannot be read. Inside the container that means '
            . 'the read-only `./` bind mount at /app/repo declared in compose.dev.yaml is missing.',
            self::MAKEFILE,
        ));

        $contents = \file_get_contents($root . '/' . self::MAKEFILE);
        $this->assertIsString($contents, self::MAKEFILE . ' is unreadable.');

        return $this->recipeIn($contents);
    }

    /**
     * The recipe's own command lines, and the assertion that the target is declared exactly once.
     * Separate from reading the file so each half is one job.
     */
    private function recipeIn(string $contents): string
    {
        $lines = \explode("\n", $contents);
        $recipe = [];
        $inTarget = false;
        $declarations = 0;

        // The whole file is scanned, never stopped at the end of the first recipe: make warns
        // `overriding recipe` and honours the LAST declaration, so a parser that returns as soon as it
        // has one recipe would vouch for a recipe make does not run — measured, with a second
        // `db.load.fixtures:` appended this counter never reached 2 and the gate stayed green.
        foreach ($lines as $line) {
            if (\str_starts_with($line, self::TARGET)) {
                ++$declarations;
                $inTarget = true;

                continue;
            }

            if (!$inTarget) {
                continue;
            }

            // GNU make ignores a blank line among recipe lines, so the recipe survives one and the
            // parser must too. Closing the window here instead reds over a file that is correct.
            if ('' === \trim($line)) {
                continue;
            }

            if (!\str_starts_with($line, "\t") && !\str_starts_with($line, ' ')) {
                $inTarget = false;

                continue;
            }

            // A `#` after the tab reaches `sh`, which treats the whole line as a comment — so the
            // command does not run while its text is still there to be matched. Measured: commenting
            // the replay out left this gate green, and the three original falsifications all missed it
            // because each removed the TEXT. Commenting a line out is how a line usually dies.
            if (\str_starts_with(\ltrim($line, " \t"), '#')) {
                continue;
            }

            if (1 === $declarations) {
                $recipe[] = $line;
            }
        }

        $this->assertSame(1, $declarations, \sprintf(
            '`%s` is declared %d times in %s; make would run the last one and this gate reads the first.',
            self::TARGET,
            $declarations,
            self::MAKEFILE,
        ));

        $this->assertNotSame([], $recipe, \sprintf('No `%s` recipe found in %s.', self::TARGET, self::MAKEFILE));

        return \implode("\n", $recipe);
    }
}
