<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\RecordAuditEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FakeMessageBus;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedAuditEntryFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\InMemoryAuditLogWriter;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(SymfonyAuditLogger::class)]
final class SymfonyAuditLoggerTest extends TestCase
{
    private const string CORRELATION_ID = '019877c2-1f3a-7b8c-8d2e-1a2b3c4d5e6f';

    private const string OCCURRED_ON = '2026-06-23T12:34:56.123456+00:00';

    public function testActivityRoutesTheSealedEntryToTheBusWithoutWriting(): void
    {
        $bus = new FakeMessageBus();
        $writer = new InMemoryAuditLogWriter();
        $auditLogger = $this->makeLogger($bus, $writer, new RecordingLogger());

        $resourceId = Uuid::generate();
        $auditLogger->log('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY, AuditResource::of('Bank', $resourceId));

        $this->assertSame([], $writer->written, 'activity must not write synchronously');
        $this->assertCount(1, $bus->dispatchedMessages);

        $message = $bus->dispatchedMessages[0];
        $this->assertInstanceOf(RecordAuditEntry::class, $message);

        $entry = $message->entry;
        $this->assertSame('BANK_ACCOUNTS_VIEWED', $entry->action, 'the action is forwarded to the factory');
        $this->assertSame(AuditLevel::ACTIVITY, $entry->level);
        $this->assertInstanceOf(AuditResource::class, $entry->resource);
        $this->assertSame('Bank', $entry->resource->type);
        $this->assertSame($resourceId, $entry->resource->id);
    }

    public function testSecurityWritesTheSealedEntrySynchronouslyWithoutDispatching(): void
    {
        $bus = new FakeMessageBus();
        $writer = new InMemoryAuditLogWriter();
        $auditLogger = $this->makeLogger($bus, $writer, new RecordingLogger());

        $auditLogger->log('ACCESS_DENIED', AuditLevel::SECURITY, AuditResource::of('Bank', Uuid::generate()));

        $this->assertCount(1, $writer->written, 'security writes the sealed entry before send');
        $this->assertSame([], $bus->dispatchedMessages, 'security does not enqueue');
    }

    public function testAnActivityFailureIsSwallowedAndLoggedWithoutLeakingContext(): void
    {
        $failure = new RuntimeException('audit transport unavailable');
        $bus = new FakeMessageBus($failure);
        $writer = new InMemoryAuditLogWriter();
        $recordingLogger = new RecordingLogger();
        $auditLogger = $this->makeLogger($bus, $writer, $recordingLogger);

        $auditLogger->log(
            'BANK_ACCOUNTS_VIEWED',
            AuditLevel::ACTIVITY,
            AuditResource::of('Bank', 'secret-id'),
            ['pii' => 'x'],
        );

        $this->assertCount(1, $recordingLogger->records, 'the gap is recorded, never silent');
        $record = $recordingLogger->records[0];
        $this->assertSame('warning', $record['level']);
        $this->assertSame(
            ['action' => 'BANK_ACCOUNTS_VIEWED', 'level' => 'activity', 'exception' => $failure],
            $record['context'],
            'the warning carries only safe keys — never metadata, actor id, or resource id',
        );
    }

    public function testASecurityFailurePropagatesAndIsNotSwallowed(): void
    {
        $bus = new FakeMessageBus();
        $writer = new InMemoryAuditLogWriter(new RuntimeException('audit_log unavailable'));
        $recordingLogger = new RecordingLogger();
        $auditLogger = $this->makeLogger($bus, $writer, $recordingLogger);

        try {
            $auditLogger->log('ACCESS_DENIED', AuditLevel::SECURITY, AuditResource::of('Bank', Uuid::generate()));
            $this->fail('the security branch must propagate the write failure');
        } catch (RuntimeException) {
            // expected: a write-before-send failure is never swallowed
        }

        $this->assertSame(
            [],
            $recordingLogger->records,
            'a security failure propagates; it is not downgraded to a warning',
        );
    }

    private function makeLogger(
        FakeMessageBus $bus,
        InMemoryAuditLogWriter $writer,
        RecordingLogger $logger,
    ): SymfonyAuditLogger {
        return new SymfonyAuditLogger(
            $bus,
            $writer,
            new FixedAuditEntryFactory(
                ActorContext::system(),
                self::CORRELATION_ID,
                new DateTimeImmutable(self::OCCURRED_ON),
            ),
            $logger,
        );
    }
}
