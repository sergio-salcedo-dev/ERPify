<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\MigrationColumnDefaults;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ratchet over `api/migrations`: no NEW migration may add a `NOT NULL` column and then drop the default
 * that made the add possible, because the column it leaves behind breaks every `INSERT` from code that
 * does not name it — reachable by redeploying the previous image tag, which does not undo the migration.
 * The rule, its boundary and its blind spots live on {@see MigrationColumnDefaults}.
 *
 * A green here proves only that the shape is absent from the files. It says nothing about whether the live
 * database matches them — that is `make db.validate`, which does not run in CI.
 *
 * @internal
 */
#[CoversNothing]
final class MigrationColumnDefaultGateTest extends TestCase
{
    #[Test]
    public function itFindsNoNewMigrationLeavingANotNullColumnWithoutADefault(): void
    {
        $offenders = [];

        foreach (MigrationColumnDefaults::migrationFilesIn($this->migrationsDirectory()) as $path) {
            $name = \basename($path);

            if (\in_array($name, MigrationColumnDefaults::EXEMPT, true)) {
                continue;
            }

            $source = \file_get_contents($path);
            $this->assertIsString($source);

            $violations = MigrationColumnDefaults::violationsIn($source);

            if ([] !== $violations) {
                $offenders[$name] = $violations;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A migration adds a NOT NULL column and drops its default in the same file. The column then '
            . 'has no default, so every INSERT that does not name it fails — including the one issued by '
            . 'the image an operator rolls back to, since redeploying a previous tag does not run down(). '
            . "Keep the DEFAULT, and if a schema listener is the table's source of truth, declare it "
            . 'there so `make db.diff` stays clean.',
        );
    }

    /**
     * The exemptions are the whole reason the sweep above can be trusted, and they are also the only way
     * to defeat it, so they are asserted literally: growing the list is an edit to THIS test, in the diff,
     * where a reviewer sees it — not a line quietly appended to a constant.
     */
    #[Test]
    public function itKeepsTheExemptionListClosed(): void
    {
        // @phpstan-ignore method.alreadyNarrowedType (the assertion IS the ratchet)
        $this->assertSame(
            MigrationColumnDefaults::EXEMPT,
            [
                'Version20260626215406.php',
                'Version20260709230444.php',
                'Version20260711171040.php',
                'Version20260723151422.php',
            ],
            'The exemption list grew. Every entry is a migration that already shipped the defect and can '
            . 'no longer be edited; a new one means it shipped again.',
        );
    }

    /**
     * A stale exemption is cover for the next offender: it names a file that no longer carries the shape,
     * so nobody re-reads why it is there. Prune it instead.
     */
    #[Test]
    public function itHasNoExemptionThatNoLongerCarriesTheShape(): void
    {
        foreach (MigrationColumnDefaults::EXEMPT as $name) {
            $path = $this->migrationsDirectory() . '/2026/' . $name;

            $this->assertFileExists($path, \sprintf('Exempt migration %s no longer exists.', $name));

            $source = \file_get_contents($path);
            $this->assertIsString($source);

            $this->assertNotSame(
                [],
                MigrationColumnDefaults::violationsIn($source),
                \sprintf('%s no longer adds a NOT NULL column and drops its default — drop the exemption.', $name),
            );
        }
    }

    /**
     * An unresolvable directory FAILS rather than skipping: a sweep that quietly finds nothing to look at
     * reports the same green as a real pass.
     */
    private function migrationsDirectory(): string
    {
        $directory = \dirname(__DIR__, 4) . '/migrations';

        $this->assertDirectoryExists($directory, 'The migrations directory is not reachable from the suite.');

        return $directory;
    }
}
