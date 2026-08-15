<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedAuditEntryFactory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\InMemoryAuditLogWriter;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingLogger;
use LogicException;
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

    // Passed straight through to the entry, so a fixed literal keeps the assertions deterministic
    // without coupling the test to the UUID factory.
    private const string RESOURCE_ID = '0190abcd-1234-7abc-8def-001122334455';

    public function testActivityWritesTheSealedEntrySynchronously(): void
    {
        $writer = new InMemoryAuditLogWriter();
        $auditLogger = $this->makeLogger($writer, new RecordingLogger());

        $resourceId = self::RESOURCE_ID;
        $auditLogger->log('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY, AuditResource::of('Bank', $resourceId));

        $this->assertCount(1, $writer->written, 'activity writes the sealed entry in the request cycle');

        $entry = $this->onlyEntry($writer);
        $this->assertSame('BANK_ACCOUNTS_VIEWED', $entry->action, 'the action is forwarded to the factory');
        $this->assertSame(AuditLevel::ACTIVITY, $entry->level);
        $this->assertInstanceOf(AuditResource::class, $entry->resource);
        $this->assertSame('Bank', $entry->resource->type);
        $this->assertSame($resourceId, $entry->resource->id);
    }

    public function testSecurityWritesTheSealedEntrySynchronously(): void
    {
        $writer = new InMemoryAuditLogWriter();
        $auditLogger = $this->makeLogger($writer, new RecordingLogger());

        $auditLogger->log('ACCESS_DENIED', AuditLevel::SECURITY, AuditResource::of('Bank', self::RESOURCE_ID));

        $this->assertCount(1, $writer->written, 'security writes the sealed entry before send');
        $this->assertSame(AuditLevel::SECURITY, $this->onlyEntry($writer)->level);
    }

    public function testItRejectsTheChangeLevelWhichTheOnFlushListenerCapturesNotTheLogger(): void
    {
        $auditLogger = $this->makeLogger(new InMemoryAuditLogWriter(), new RecordingLogger());

        $this->expectException(LogicException::class);

        $auditLogger->log('BANK_UPDATED', AuditLevel::CHANGE);
    }

    public function testAnActivityFailureIsSwallowedAndLoggedWithoutLeakingContext(): void
    {
        $failure = new RuntimeException('audit_log unavailable');
        $writer = new InMemoryAuditLogWriter($failure);
        $recordingLogger = new RecordingLogger();
        $auditLogger = $this->makeLogger($writer, $recordingLogger);

        $auditLogger->log(
            'BANK_ACCOUNTS_VIEWED',
            AuditLevel::ACTIVITY,
            AuditResource::of('Bank', self::RESOURCE_ID),
            ['pii' => 'x'],
        );

        $this->assertCount(1, $recordingLogger->records, 'the gap is recorded, never silent');
        $record = $recordingLogger->records[0];
        // A lost audit row is an integrity defect rather than a metric, so the report is an error. What
        // decides whether it SURVIVES is the channel, not this level — see the class docblock; the arrival
        // test is what pins that half, against the files Monolog actually wrote.
        $this->assertSame('error', $record['level']);
        $this->assertSame(
            ['action' => 'BANK_ACCOUNTS_VIEWED', 'level' => 'activity', 'exception' => $failure],
            $record['context'],
            'the line carries only safe keys — never metadata, actor id, or resource id',
        );
    }

    public function testASecurityFailurePropagatesAndIsNotSwallowed(): void
    {
        $writer = new InMemoryAuditLogWriter(new RuntimeException('audit_log unavailable'));
        $recordingLogger = new RecordingLogger();
        $auditLogger = $this->makeLogger($writer, $recordingLogger);

        try {
            $auditLogger->log('ACCESS_DENIED', AuditLevel::SECURITY, AuditResource::of('Bank', self::RESOURCE_ID));
            $this->fail('the security branch must propagate the write failure');
        } catch (RuntimeException) {
            // expected: a write-before-send failure is never swallowed
        }

        $this->assertSame(
            [],
            $recordingLogger->records,
            'a security failure propagates; it is never downgraded to a log line',
        );
    }

    private function onlyEntry(InMemoryAuditLogWriter $writer): AuditLogEntry
    {
        $entry = \array_first($writer->written) ?? null;
        $this->assertInstanceOf(AuditLogEntry::class, $entry);

        return $entry;
    }

    private function makeLogger(
        InMemoryAuditLogWriter $writer,
        RecordingLogger $logger,
    ): SymfonyAuditLogger {
        return new SymfonyAuditLogger(
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
