<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditLogEntry;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The entry's reservation of `metadata.changes` for the `change` level — the write-side half of the audit
 * detail route's provenance rule, which reads the level as proof the diff was sealed.
 *
 * @internal
 */
#[CoversClass(AuditLogEntry::class)]
#[CoversClass(InvalidAuditLogEntry::class)]
final class AuditLogEntryChangesKeyTest extends TestCase
{
    /**
     * Only the change-capture listener seals a diff's personal values, so a `changes` key on any other level
     * would reach storage in clear, out of every erasure pass's reach. `null` counts: the key is the claim.
     */
    #[DataProvider('provideRejectsAChangesKeyOutsideAChangeEntryCases')]
    public function testRejectsAChangesKeyOutsideAChangeEntry(AuditLevel $level, mixed $changes): void
    {
        $this->expectException(InvalidAuditLogEntry::class);
        $this->expectExceptionMessageIsOrContains('metadata.changes is reserved for change-level entries');

        AuditLogEntry::create(
            'BANK_UPDATED',
            $level,
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: [AuditLogEntry::CHANGES_KEY => $changes],
        );
    }

    /**
     * @return iterable<string, array{AuditLevel, mixed}>
     */
    public static function provideRejectsAChangesKeyOutsideAChangeEntryCases(): iterable
    {
        $diff = ['email' => ['old' => 'jane.doe@example.test', 'new' => 'jane@example.test']];

        yield 'activity, a diff-shaped map' => [AuditLevel::ACTIVITY, $diff];
        yield 'security, a diff-shaped map' => [AuditLevel::SECURITY, $diff];
        yield 'activity, an empty map' => [AuditLevel::ACTIVITY, []];
        yield 'security, a scalar' => [AuditLevel::SECURITY, 'jane.doe@example.test'];
        yield 'activity, null' => [AuditLevel::ACTIVITY, null];
    }

    public function testAcceptsAChangesKeyOnAChangeEntry(): void
    {
        $diff = ['name' => ['old' => 'BBVA', 'new' => 'BBVA S.A.']];

        $entry = AuditLogEntry::create(
            'BANK_UPDATED',
            AuditLevel::CHANGE,
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: [AuditLogEntry::CHANGES_KEY => $diff],
        );

        $this->assertSame([AuditLogEntry::CHANGES_KEY => $diff], $entry->metadata);
    }
}
