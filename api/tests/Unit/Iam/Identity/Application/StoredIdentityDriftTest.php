<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\StoredIdentityDrift;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditProvenance;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `isClean()` is the one branch that decides `SUCCESS` over `FAILURE`, so every fact it reads is exercised on
 * its own: a clean branch that has fallen behind a newly added finding does not surface as a failure — it
 * surfaces as the control certifying a table it just read a finding out of.
 *
 * @internal
 */
#[CoversClass(StoredIdentityDrift::class)]
final class StoredIdentityDriftTest extends TestCase
{
    #[Test]
    public function itIsCleanOnlyWhenEveryFactIsEmpty(): void
    {
        $this->assertTrue((new StoredIdentityDrift([], 0, 0, 0))->isClean());
    }

    /**
     * @param list<string> $orphanRoleValues
     */
    #[Test]
    #[DataProvider('provideItIsNotCleanWhenAnySingleFactIsPresentCases')]
    public function itIsNotCleanWhenAnySingleFactIsPresent(
        array $orphanRoleValues,
        int $malformedRoles,
        int $unreadableCredentials,
        int $admittedWithoutCredential,
    ): void {
        $drift = new StoredIdentityDrift(
            $orphanRoleValues,
            $malformedRoles,
            $unreadableCredentials,
            $admittedWithoutCredential,
        );

        $this->assertFalse($drift->isClean());
    }

    /**
     * @return iterable<string, array{list<string>, int, int, int}>
     */
    public static function provideItIsNotCleanWhenAnySingleFactIsPresentCases(): iterable
    {
        yield 'an orphan role value' => [['GHOST_ROLE'], 0, 0, 0];

        yield 'a malformed roles column' => [[], 1, 0, 0];

        yield 'an unreadable credential' => [[], 0, 1, 0];

        yield 'an admitted identity with no credential' => [[], 0, 0, 1];
    }

    #[Test]
    public function itCarriesEveryFactIntoTheAuditMetadataAndNothingElse(): void
    {
        $drift = new StoredIdentityDrift(['GHOST_ROLE'], 1, 2, 3);

        // Asserted as a whole rather than key by key: what must never appear here is as much the contract as
        // what must — this is the payload that reaches `audit_log.metadata`, and an identity id in it would
        // be the person reference the report is built to avoid.
        $this->assertSame([
            'orphan_role_values' => ['GHOST_ROLE'],
            'orphan_role_value_count' => 1,
            'malformed_roles' => 1,
            'unreadable_credentials' => 2,
            'admitted_without_credential' => 3,
        ], $drift->toAuditMetadata());
    }

    /**
     * The corruption decides how many orphan values there are and how long each is, and the audit row is bounded:
     * a row refused for its size would leave the trail with no record of the finding. So the row samples them,
     * keeps the total, and must still fit the bound when every sampled character costs the most it can.
     */
    #[Test]
    public function itSamplesTheOrphanValuesSoTheAuditRowAlwaysFitsItsBound(): void
    {
        $values = \array_map(
            static fn (int $index): string => \sprintf('%03d', $index) . \str_repeat("\u{0001}", 1_000),
            \range(1, 500),
        );

        $metadata = (new StoredIdentityDrift($values, 0, 0, 0))->toAuditMetadata();

        $this->assertSame(500, $metadata['orphan_role_value_count']);
        $this->assertCount(StoredIdentityDrift::SAMPLED_ORPHAN_VALUES, $metadata['orphan_role_values']);
        $this->assertSame(
            '001' . \str_repeat("\u{0001}", StoredIdentityDrift::SAMPLED_VALUE_LENGTH - 3),
            \array_first($metadata['orphan_role_values']),
        );

        $entry = AuditLogEntry::create(
            'STORED_IDENTITY_DRIFT_DETECTED',
            AuditLevel::SECURITY,
            new AuditProvenance(
                ActorContext::system(),
                Uuid::generate(),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ),
            metadata: $metadata,
        );

        $this->assertSame($metadata, $entry->metadata);
    }
}
