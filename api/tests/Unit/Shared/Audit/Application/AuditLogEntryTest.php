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
use Symfony\Component\Uid\Uuid as SymfonyUuid;
use Symfony\Component\Uid\UuidV7;

/**
 * @internal
 */
#[CoversClass(AuditLogEntry::class)]
#[CoversClass(InvalidAuditLogEntry::class)]
final class AuditLogEntryTest extends TestCase
{
    public function testExposesEveryFieldItIsBuiltWith(): void
    {
        $actor = ActorContext::forUser(Uuid::generate());
        $correlationId = Uuid::generate();
        $resourceId = Uuid::generate();
        $occurredOn = new DateTimeImmutable('2026-01-01T12:34:56.789012+00:00');
        $metadata = ['filters' => ['status' => 'active']];

        $entry = AuditLogEntry::create(
            AuditLevel::SECURITY,
            'BANK_ACCOUNTS_VIEWED',
            $actor,
            $correlationId,
            $occurredOn,
            'bank_account',
            $resourceId,
            $metadata,
            '203.0.113.7',
            'Mozilla/5.0',
        );

        $this->assertSame(AuditLevel::SECURITY, $entry->level);
        $this->assertSame('BANK_ACCOUNTS_VIEWED', $entry->action);
        $this->assertSame($actor, $entry->actor);
        $this->assertSame($correlationId, $entry->correlationId);
        $this->assertSame($occurredOn, $entry->occurredOn);
        $this->assertSame('bank_account', $entry->resourceType);
        $this->assertSame($resourceId, $entry->resourceId);
        $this->assertSame($metadata, $entry->metadata);
        $this->assertSame('203.0.113.7', $entry->ip);
        $this->assertSame('Mozilla/5.0', $entry->userAgent);
    }

    public function testMintsAValidTimeOrderedV7Id(): void
    {
        $entry = $this->anEntry();

        $this->assertTrue(Uuid::isValid($entry->id));
        $this->assertInstanceOf(UuidV7::class, SymfonyUuid::fromString($entry->id));
    }

    public function testOptionalContextDefaultsToNullAndEmptyMetadata(): void
    {
        $entry = $this->anEntry();

        $this->assertNull($entry->resourceType);
        $this->assertNull($entry->resourceId);
        $this->assertSame([], $entry->metadata);
        $this->assertNull($entry->ip);
        $this->assertNull($entry->userAgent);
    }

    #[DataProvider('provideRejectsBlankActionCases')]
    public function testRejectsBlankAction(string $blank): void
    {
        $this->expectException(InvalidAuditLogEntry::class);

        AuditLogEntry::create(
            AuditLevel::ACTIVITY,
            $blank,
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsBlankActionCases(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
    }

    #[DataProvider('provideRejectsAFieldLongerThanTheColumnWidthCases')]
    public function testRejectsAFieldLongerThanTheColumnWidth(string $action, ?string $resourceType): void
    {
        $this->expectException(InvalidAuditLogEntry::class);

        AuditLogEntry::create(
            AuditLevel::ACTIVITY,
            $action,
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $resourceType,
        );
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideRejectsAFieldLongerThanTheColumnWidthCases(): iterable
    {
        yield 'over-long action' => [\str_repeat('A', 101), null];
        yield 'over-long resourceType' => ['BANK_ACCOUNTS_VIEWED', \str_repeat('A', 101)];
    }

    public function testAcceptsFieldsAtTheColumnWidthBoundary(): void
    {
        $action = \str_repeat('A', 100);
        $resourceType = \str_repeat('B', 100);

        $entry = AuditLogEntry::create(
            AuditLevel::ACTIVITY,
            $action,
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $resourceType,
        );

        $this->assertSame($action, $entry->action);
        $this->assertSame($resourceType, $entry->resourceType);
    }

    private function anEntry(): AuditLogEntry
    {
        return AuditLogEntry::create(
            AuditLevel::ACTIVITY,
            'BANK_ACCOUNTS_VIEWED',
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }
}
