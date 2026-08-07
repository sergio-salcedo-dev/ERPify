<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\MigrationColumnDefaults;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the rule its sibling sweeps with. {@see MigrationColumnDefaultGateTest} runs against a
 * tree where every occurrence of the shape is exempt, so it is green over an EMPTY set — it cannot tell a
 * working rule from one that never matches anything. These fixtures are the states it has to see.
 *
 * The reds matter more than the greens: a rule that missed the Doctrine-generated `ADD <col>` spelling, or
 * that only looked for `DEFAULT` before `NOT NULL`, would sweep the tree happily and let the next
 * migration through.
 *
 * @internal
 */
#[CoversNothing]
final class MigrationColumnDefaultRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/MigrationColumnDefault';

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideItSeesTheColumnLeftWithoutADefaultCases')]
    public function itSeesTheColumnLeftWithoutADefault(string $fixture, array $expected): void
    {
        $this->assertSame($expected, MigrationColumnDefaults::violationsIn($this->read($fixture)));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideItSeesTheColumnLeftWithoutADefaultCases(): iterable
    {
        yield 'the hand-written shape the tree already carries' => [
            'add-then-drop.migration',
            ['audit_log.actor_erased'],
        ];

        // Doctrine writes `ALTER TABLE x ADD col TYPE NOT NULL` with no COLUMN keyword, and the tree has
        // four of them. A rule keyed on `ADD COLUMN` would pass every auto-generated migration blind.
        yield 'the Doctrine-generated spelling, with no COLUMN keyword' => [
            'doctrine-generated-add-then-drop.migration',
            ['bank.stored_object_key'],
        ];

        // `DEFAULT 'ACTIVE' NOT NULL` — the modifiers are unordered in the tree, and a rule that expected
        // `NOT NULL DEFAULT …` would see this one as nullable.
        yield 'the modifiers in the other order' => [
            'default-before-not-null.migration',
            ['identity_user.status'],
        ];

        // The shortest route to the defect, and the one a rule keyed on the ADD-then-DROP pair misses
        // entirely: no default is ever declared, so there is none to drop.
        yield 'NOT NULL declared with no default at all' => [
            'not-null-with-no-default-at-all.migration',
            ['audit_log.actor_classification'],
        ];

        // Two statements reaching the same end state. The ADD alone looks nullable and harmless.
        yield 'added nullable, then tightened to NOT NULL' => [
            'nullable-then-tightened.migration',
            ['audit_log.second_flag'],
        ];

        // `db.diff` writes multi-table migrations routinely and this schema repeats `status` across tables.
        // Keyed on the bare name the two rows collapse into one, the compliant table answers for the other,
        // and the offender is reported as clean.
        yield 'the same column name in two tables, one of them defective' => [
            'same-column-name-in-two-tables.migration',
            ['bank.status'],
        ];

        // The reversal is only invisible because `up()` is what gets read; the declaration in `up()` still
        // has to be seen when a `down()` is present. Without this the pair below proves only silence.
        yield 'declared in up(), reversed in down()' => [
            'up-declares-it-and-down-reverses-it.migration',
            ['audit_log.actor_classification'],
        ];

        // One `addSql()` can carry two statements. Reading the first table for both files the compliant
        // column and the defective one under one key, where the last write wins and the offender reads clean
        // — the same collapse the two-table fixture above covers BETWEEN calls, here WITHIN one.
        yield 'two tables inside a single addSql()' => [
            'two-tables-in-one-statement.migration',
            ['bank.status'],
        ];
    }

    #[Test]
    #[DataProvider('provideItLeavesALegitimateMigrationAloneCases')]
    public function itLeavesALegitimateMigrationAlone(string $fixture): void
    {
        $this->assertSame([], MigrationColumnDefaults::violationsIn($this->read($fixture)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItLeavesALegitimateMigrationAloneCases(): iterable
    {
        yield 'a NOT NULL column that keeps its default' => ['default-kept.migration'];

        // What a correct down() looks like, and what the reversal of this very fix does. Pairing by column
        // NAME is what keeps it out of the offender list.
        yield 'a default dropped from a column this migration did not add' => [
            'default-dropped-on-preexisting-column.migration',
        ];

        // A nullable column with no default is not the defect: an INSERT that omits it writes NULL.
        yield 'a nullable column losing its default' => ['nullable-column.migration'];

        yield 'ADD CONSTRAINT, the one ADD that is not a column' => ['constraint-is-not-a-column.migration'];

        yield 'DROP NOT NULL, which is a different statement' => [
            'drop-not-null-is-not-drop-default.migration',
        ];

        // Tightening a column that KEEPS its default is the correct way to do it, and must not be swept up
        // by the rule that catches its defective twin.
        yield 'added with a default, then tightened to NOT NULL' => [
            'nullable-then-tightened-with-default.migration',
        ];

        // Re-adding a `NOT NULL` column with no default is what a correct `down()` IS. It runs against the
        // schema its own `up()` built, never against a live database an older image writes to, so reading it
        // would fail the first migration that deletes such a column — and the only ways out of that red are
        // an invented DEFAULT or a longer exemption list.
        yield 'a NOT NULL column re-added by down()' => ['down-reverses-a-dropped-column.migration'];

        // Every `ADD` that is followed by something which is not a column name. Missing one of these words
        // does not miss a defect, it invents one: `ADD CHECK (code IS NOT NULL)` reads as a column named
        // `check`, `NOT NULL`, with no default — a red on a correct migration.
        yield 'ADD CHECK and ADD PRIMARY KEY, neither of which is a column' => [
            'check-constraint-is-not-a-column.migration',
        ];
    }

    /**
     * The sweep is only as good as the file list it walks, and an empty list is the silent way for this
     * gate to mean nothing. Pinned against the exemptions rather than a count: those are the files whose
     * presence the sweep depends on, and a count would only encode today's number of migrations.
     */
    #[Test]
    public function itWalksTheRealMigrationTree(): void
    {
        $found = \array_map(
            \basename(...),
            MigrationColumnDefaults::migrationFilesIn(\dirname(__DIR__, 4) . '/migrations'),
        );

        $this->assertNotEmpty($found, 'The migration sweep found no files to read.');

        foreach (MigrationColumnDefaults::EXEMPT as $exempt) {
            $this->assertContains(
                $exempt,
                $found,
                \sprintf('The walker does not reach %s, so exempting it proves nothing.', $exempt),
            );
        }
    }

    private function read(string $fixture): string
    {
        $source = \file_get_contents(self::FIXTURES . '/' . $fixture);

        $this->assertIsString($source, \sprintf('Fixture %s is missing.', $fixture));

        return $source;
    }
}
