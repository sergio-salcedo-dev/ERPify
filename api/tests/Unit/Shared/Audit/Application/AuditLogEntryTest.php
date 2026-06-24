<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
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
            AuditResource::of('bank_account', $resourceId),
            $metadata,
            '203.0.113.7',
            'Mozilla/5.0',
        );

        $this->assertSame(AuditLevel::SECURITY, $entry->level);
        $this->assertSame('BANK_ACCOUNTS_VIEWED', $entry->action);
        $this->assertSame($actor, $entry->actor);
        $this->assertSame($correlationId, $entry->correlationId);
        $this->assertSame($occurredOn, $entry->occurredOn);
        $this->assertInstanceOf(AuditResource::class, $entry->resource);
        $this->assertSame('bank_account', $entry->resource->type);
        $this->assertSame($resourceId, $entry->resource->id);
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

    public function testOmittingTheResourceLeavesItNullAlongsideTheOtherOptionalContext(): void
    {
        $entry = $this->anEntry();

        $this->assertNotInstanceOf(AuditResource::class, $entry->resource);
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

    public function testRejectsAnActionLongerThanTheColumnWidth(): void
    {
        $this->expectException(InvalidAuditLogEntry::class);

        AuditLogEntry::create(
            AuditLevel::ACTIVITY,
            \str_repeat('A', 101),
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    public function testRejectsAResourceTypeLongerThanTheColumnWidth(): void
    {
        $this->expectException(InvalidAuditLogEntry::class);

        AuditLogEntry::create(
            AuditLevel::ACTIVITY,
            'BANK_ACCOUNTS_VIEWED',
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            AuditResource::of(\str_repeat('A', 101), Uuid::generate()),
        );
    }

    public function testAcceptsAnActionAndResourceTypeAtTheColumnWidthBoundary(): void
    {
        $action = \str_repeat('A', 100);
        $resourceType = \str_repeat('B', 100);

        $entry = AuditLogEntry::create(
            AuditLevel::ACTIVITY,
            $action,
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            AuditResource::of($resourceType, Uuid::generate()),
        );

        $this->assertSame($action, $entry->action);
        $this->assertInstanceOf(AuditResource::class, $entry->resource);
        $this->assertSame($resourceType, $entry->resource->type);
    }

    public function testTrimsTheAction(): void
    {
        $entry = AuditLogEntry::create(
            AuditLevel::ACTIVITY,
            '  BANK_ACCOUNTS_VIEWED  ',
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $this->assertSame('BANK_ACCOUNTS_VIEWED', $entry->action);
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
