<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\StoredIdentityDrift;
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
            'malformed_roles' => 1,
            'unreadable_credentials' => 2,
            'admitted_without_credential' => 3,
        ], $drift->toAuditMetadata());
    }
}
