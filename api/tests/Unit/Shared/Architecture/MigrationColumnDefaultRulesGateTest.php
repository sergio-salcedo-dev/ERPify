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
            ['actor_erased'],
        ];

        // Doctrine writes `ALTER TABLE x ADD col TYPE NOT NULL` with no COLUMN keyword, and the tree has
        // four of them. A rule keyed on `ADD COLUMN` would pass every auto-generated migration blind.
        yield 'the Doctrine-generated spelling, with no COLUMN keyword' => [
            'doctrine-generated-add-then-drop.migration',
            ['stored_object_key'],
        ];

        // `DEFAULT 'ACTIVE' NOT NULL` — the modifiers are unordered in the tree, and a rule that expected
        // `NOT NULL DEFAULT …` would see this one as nullable.
        yield 'the modifiers in the other order' => [
            'default-before-not-null.migration',
            ['status'],
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
