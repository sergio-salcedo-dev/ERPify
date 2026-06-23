<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\RecordAuditEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RecordAuditEntry::class)]
final class RecordAuditEntryTest extends TestCase
{
    public function testWrapsTheAuditLogEntryWithoutCopyingIt(): void
    {
        $entry = $this->anEntry();

        $message = new RecordAuditEntry($entry);

        $this->assertSame($entry, $message->entry);
    }

    public function testHasNoBaseClassSoTheEventBackboneNeverSeesIt(): void
    {
        // A class reaches the event backbone only by extending the DomainEvent base — the
        // registration pass and the EventBus signature both key off that inheritance — so
        // having no parent class is what keeps this transport message off it entirely.
        $reflection = new ReflectionClass(RecordAuditEntry::class);

        $this->assertFalse($reflection->getParentClass());
    }

    public function testSurvivesTransportSerializationWithoutLosingPrecision(): void
    {
        $actor = ActorContext::forUser(Uuid::generate());
        $occurredOn = new DateTimeImmutable('2026-01-01T12:34:56.789012+02:00');
        $entry = AuditLogEntry::create(
            AuditLevel::SECURITY,
            'BANK_ACCOUNTS_VIEWED',
            $actor,
            Uuid::generate(),
            $occurredOn,
        );
        $message = new RecordAuditEntry($entry);

        $serialized = \serialize($message);
        $round = \unserialize($serialized);

        $this->assertInstanceOf(RecordAuditEntry::class, $round);
        $this->assertSame($serialized, \serialize($round));
        $this->assertSame(ActorType::USER, $round->entry->actor->type);
        $this->assertSame($actor->actorId, $round->entry->actor->actorId);
        $this->assertSame(AuditLevel::SECURITY, $round->entry->level);
        $this->assertSame(
            $occurredOn->format('Y-m-d\TH:i:s.uP'),
            $round->entry->occurredOn->format('Y-m-d\TH:i:s.uP'),
        );
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
