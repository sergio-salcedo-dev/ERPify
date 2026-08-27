<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\RecordLockoutNoticeAuditBestEffort;
use Erpify\Iam\Identity\Application\ReportsAuditFailureSafely;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The swallow-and-log contract of the notice projection, pinned on its own — mirroring
 * {@see RecordLockoutAuditBestEffortTest} for the trip's own row. {@see NotifyLockedIdentitiesTest} exercises
 * this class through the sweep but only asserts the row and that a failure here does not undo the already-
 * sent mail or the already-committed stamp, so deleting the `logger->error` call and leaving a bare `catch`
 * would leave that suite green — the failure mode of a best-effort projection is silence, and nothing else in
 * the suite could see it.
 *
 * @internal
 */
#[CoversClass(RecordLockoutNoticeAuditBestEffort::class)]
#[CoversTrait(ReportsAuditFailureSafely::class)]
final class RecordLockoutNoticeAuditBestEffortTest extends TestCase
{
    public function testPassesTheNoticeThroughToTheAuditLoggerWithTheExpiryAsMetadata(): void
    {
        $subjectId = Uuid::generate();
        $lockedUntil = new DateTimeImmutable('2026-08-11T12:15:00+00:00');
        $auditLogger = new RecordingAuditLogger();
        $logger = new RecordingLogger();

        (new RecordLockoutNoticeAuditBestEffort($auditLogger, $logger))->record($subjectId, $lockedUntil);

        $this->assertCount(1, $auditLogger->records);
        $record = $auditLogger->records[0];
        $this->assertSame('ACCOUNT_LOCKOUT_NOTIFIED', $record['action']);
        $this->assertSame(AuditLevel::SECURITY, $record['level']);

        $resource = $record['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame($subjectId, $resource->id);
        $this->assertSame(['lockedUntil' => '2026-08-11T12:15:00+00:00'], $record['metadata']);
        $this->assertSame([], $logger->records, 'A successful projection must not log.');
    }

    public function testANullExpiryCarriesNoMetadata(): void
    {
        $auditLogger = new RecordingAuditLogger();

        (new RecordLockoutNoticeAuditBestEffort($auditLogger, new RecordingLogger()))
            ->record(Uuid::generate(), null)
        ;

        $this->assertCount(1, $auditLogger->records);
        $this->assertSame([], $auditLogger->records[0]['metadata']);
    }

    public function testSwallowsAFailedAuditWriteAndLogsItAtError(): void
    {
        $failure = new RuntimeException('audit_log is unavailable');
        $logger = new RecordingLogger();

        (new RecordLockoutNoticeAuditBestEffort(new FailingAuditLogger($failure), $logger))
            ->record(Uuid::generate(), null)
        ;

        $this->assertCount(
            1,
            $logger->records,
            'A swallowed projection failure that logs nothing is an effect that silently did not happen.',
        );
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame($failure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testTheLogLineNamesNoSubject(): void
    {
        // The id is the personal datum this control handles. The sweep drives this path once per lockout
        // window, so naming the subject here would write a stream of person ids into the log the erasure
        // chain does not reach. (The swallowed exception's own message is outside this class's control.)
        $subjectId = Uuid::generate();
        $logger = new RecordingLogger();

        (new RecordLockoutNoticeAuditBestEffort(new FailingAuditLogger(), $logger))->record($subjectId, null);

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertStringNotContainsString($subjectId, $record['message']);
        $this->assertSame(['exception'], \array_keys($record['context']));
    }

    public function testALoggerFailureWhileReportingDoesNotEscape(): void
    {
        // The outer catch protects the audit write; nothing protected the report of that failure. A failing
        // sink used to escape from INSIDE the catch and abort NotifyLockedIdentities::notifyLockedOwners()'s
        // whole tick — every remaining locked identity in that run would go unreported, not just this one.
        $this->expectNotToPerformAssertions();

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new RuntimeException('stderr pipe closed'));

        (new RecordLockoutNoticeAuditBestEffort(new FailingAuditLogger(), $logger))
            ->record(Uuid::generate(), null)
        ;
    }
}
