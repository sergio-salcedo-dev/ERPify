<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\PhpSource;
use Erpify\Tests\Support\SanctionedLogMutations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the claim the audit detail route rests on: a `change` row is one the change-capture
 * listener wrote, so its `metadata.changes` went through `PiiDiffSealer`.
 *
 * `AuditEventDetailResourceMapper` serves a `changes` map only on a `change` row and withholds it on every
 * other, because a map has the shape of a sealed diff whether or not anything sealed it. That makes the level
 * the provenance, and the level is only worth that while one producer can mint it. Three things hold it, and
 * this gate pins the two that are text: `AuditLogEntry` refuses `changes` on any other level (a behavioural
 * test, {@see \Erpify\Tests\Unit\Shared\Audit\Application\AuditLogEntryChangesKeyTest}), the set of files
 * naming `AuditLevel::CHANGE` is closed, and `src` appends to `audit_log` through one class, the writer that
 * persists an `AuditLogEntry` — so a row written any other way cannot skip the entry's own refusal.
 *
 * The level patterns read the de-commented source case-insensitively, as PHP resolves class and method names,
 * and admit whitespace around `::`. The append is read by {@see SanctionedLogMutations::appendsIn()}, the same
 * machinery that holds the non-append mutations of this table closed: it follows `.` concatenations and a
 * same-file `self::TABLE`, reads a quoted or schema-qualified name, and reads DBAL's `->insert('audit_log')`.
 *
 * **What a green proves:** no file outside the pinned set names the case, nothing mints the enum from a string
 * by name, and one class appends to the table. Not that the listener seals every field it should (that is
 * `PiiDiffSealerTest`), and not that a row already stored by another road is sealed — a `change` row written
 * before sealing existed or inserted by hand in the database is the residual recorded in
 * `PRODUCTION_SECURITY_CHECKLIST.md` §7. It is a text match, so it does not see the case reached through
 * `AuditLevel::cases()` (the retention policy iterates them to build one window per level), through an alias
 * of the enum, through `constant()` or a callable array, through a class held in a variable (`$enum::from()`),
 * or through a denormaliser or a Doctrine `enumType` that builds the enum without spelling it; nor an insert
 * whose table name comes from another class's constant, a `sprintf` or any value only known at runtime.
 *
 * @internal
 */
#[CoversNothing]
final class ChangeLevelProvenanceGateTest extends TestCase
{
    /**
     * Every file allowed to name the case, with the one role each has. A sixth entry is a decision: a new
     * producer would make the level stop proving the diff was sealed.
     */
    private const array CHANGE_LEVEL_SITES = [
        'Backoffice/Audit/Infrastructure/Http/AuditEventDetailResourceMapper.php' => 'reads it as provenance',
        'Shared/Audit/Application/AuditLogEntry.php' => 'refuses `changes` on every other level',
        'Shared/Audit/Domain/AuditRetentionPolicy.php' => 'sizes its retention window',
        'Shared/Audit/Infrastructure/Persistence/AuditWriteCaptureListener.php' => 'the one producer',
        'Shared/Audit/Infrastructure/SymfonyAuditLogger.php' => 'refuses it on the public logger',
    ];

    private const string WRITER = 'Shared/Audit/Infrastructure/Persistence/DbalAuditLogWriter.php';

    /** `{` is the dynamic class-constant fetch, `AuditLevel::{'CHANGE'}`, which spells no case at all. */
    private const string CHANGE_LEVEL = '/\bAuditLevel\s*::\s*(?:CHANGE\b|\{)/i';

    private const string MINTED_FROM_A_STRING = '/\bAuditLevel\s*::\s*(?:try)?from\s*\(/i';

    #[Test]
    public function theChangeLevelIsNamedOnlyByItsPinnedSites(): void
    {
        $sites = $this->filesWhere(
            static fn (string $source): bool => 1 === \preg_match(
                self::CHANGE_LEVEL,
                PhpSource::withoutComments($source),
            ),
        );

        $this->assertSame(\array_keys(self::CHANGE_LEVEL_SITES), $sites, \sprintf(
            'The files naming AuditLevel::CHANGE are no longer the pinned set. Found: %s. The audit detail route '
            . 'serves a `changes` map only on a `change` row, on the claim that only the change-capture listener '
            . 'mints one and that listener seals the diff. A second producer makes that claim false and serves '
            . 'its map in clear; if the new site only reads the level, add it to CHANGE_LEVEL_SITES with its role.',
            \implode(', ', $sites),
        ));
    }

    /**
     * `from()` and `tryFrom()` turn a string into a level, which is the one way to reach the case without
     * spelling it — and so the one way past the assertion above.
     */
    #[Test]
    public function noLevelIsMintedFromAString(): void
    {
        $sites = $this->filesWhere(
            static fn (string $source): bool => 1 === \preg_match(
                self::MINTED_FROM_A_STRING,
                PhpSource::withoutComments($source),
            ),
        );

        $this->assertSame([], $sites, \sprintf(
            'AuditLevel is minted from a string in: %s. A level built at runtime can be `change` without naming '
            . 'the case, which walks around the closed set of producers the audit detail route relies on.',
            \implode(', ', $sites),
        ));
    }

    /**
     * Scoped to `src`, not to the writer's own file: what the provenance needs is that no OTHER class writes the
     * table, because a raw INSERT never builds an `AuditLogEntry` and so never meets its refusal of `changes`.
     */
    #[Test]
    public function srcAppendsToTheAuditLogThroughTheEntryWriterAlone(): void
    {
        $sites = $this->filesWhere(
            static fn (string $source): bool => \in_array(
                'INSERT audit_log',
                SanctionedLogMutations::appendsIn($source),
                true,
            ),
        );

        $this->assertSame([self::WRITER], $sites, \sprintf(
            'Exactly one class in src may append to audit_log, and it is the writer that persists an '
            . 'AuditLogEntry. Found: %s. A second append path writes rows the entry never validated — a `change` '
            . 'level or a `changes` key included — and the detail route would read its level as provenance.',
            \implode(', ', $sites),
        ));
    }

    /**
     * @param callable(string): bool $matches
     *
     * @return list<string> paths relative to `src`, sorted
     */
    private function filesWhere(callable $matches): array
    {
        $sites = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $source = \file_get_contents($file->getPathname());

            if (!\is_string($source) || !$matches($source)) {
                continue;
            }

            $sites[] = \substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1);
        }

        \sort($sites);

        return $sites;
    }
}
