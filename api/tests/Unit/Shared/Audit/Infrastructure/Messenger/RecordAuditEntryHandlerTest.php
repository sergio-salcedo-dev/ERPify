<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Messenger;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\RecordAuditEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Messenger\RecordAuditEntryHandler;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\InMemoryAuditLogWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RecordAuditEntryHandler::class)]
final class RecordAuditEntryHandlerTest extends TestCase
{
    public function testItWritesExactlyTheReceivedEntry(): void
    {
        $writer = new InMemoryAuditLogWriter();
        $entry = $this->entry();
        $handler = new RecordAuditEntryHandler($writer);

        $handler(new RecordAuditEntry($entry));

        $this->assertCount(1, $writer->written);
        $this->assertContains($entry, $writer->written);
    }

    public function testARedeliveryOfTheSameEntryIsANoOp(): void
    {
        $writer = new InMemoryAuditLogWriter();
        $handler = new RecordAuditEntryHandler($writer);
        $message = new RecordAuditEntry($this->entry());

        $handler($message);
        $handler($message);

        $this->assertCount(1, $writer->written);
    }

    private function entry(): AuditLogEntry
    {
        return AuditLogEntry::create(
            AuditLevel::ACTIVITY,
            'BANK_ACCOUNTS_VIEWED',
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-06-23T12:00:00+00:00'),
        );
    }
}
