<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\RecordLockoutAuditBestEffort;
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
 * The swallow-and-log contract of the lockout projection, pinned on its own. {@see LoginAttemptRegistrarAuditTest}
 * exercises the class through the use case but only asserts the row and the commit boundary, so deleting the
 * `logger->error` call and leaving a bare `catch` left the whole suite green — the failure mode of a
 * best-effort projection is silence, and nothing else in the suite could see it.
 *
 * @internal
 */
#[CoversClass(RecordLockoutAuditBestEffort::class)]
#[CoversTrait(ReportsAuditFailureSafely::class)]
final class RecordLockoutAuditBestEffortTest extends TestCase
{
    public function testPassesTheLockoutThroughToTheAuditLogger(): void
    {
        $subjectId = Uuid::generate();
        $auditLogger = new RecordingAuditLogger();
        $logger = new RecordingLogger();

        (new RecordLockoutAuditBestEffort(
            $auditLogger,
            InMemoryIdentityRowLock::serialiser(),
            $logger,
        ))->record($subjectId);

        $this->assertCount(1, $auditLogger->records);
        $record = $auditLogger->records[0];
        $this->assertSame('USER_LOCKED', $record['action']);
        $this->assertSame(AuditLevel::SECURITY, $record['level']);

        $resource = $record['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame($subjectId, $resource->id);
        $this->assertSame([], $record['metadata'], 'Metadata would reach json_encode on a path that cannot throw.');
        $this->assertSame([], $logger->records, 'A successful projection must not log.');
    }

    public function testWritesBehindTheSubjectsRowLock(): void
    {
        $subjectId = Uuid::generate();
        $rows = new InMemoryIdentityRowLock();
        $auditLogger = new RecordingAuditLogger();

        $this->recorder($auditLogger, $rows, new RecordingLogger())->record($subjectId);

        $this->assertSame([$subjectId], $rows->locked);
        $this->assertCount(1, $auditLogger->records);
    }

    public function testASubjectErasedBeforeTheLockGetsNoRowAndNoReport(): void
    {
        // The erasure committed while the lock waited, or before it was asked for: the row would name an
        // identity that no longer exists, outside every pass that could rewrite it. Not a failure either —
        // there is nothing an operator could act on.
        $subjectId = Uuid::generate();
        $rows = new InMemoryIdentityRowLock();
        $rows->gone[] = $subjectId;
        $auditLogger = new RecordingAuditLogger();
        $logger = new RecordingLogger();

        $this->recorder($auditLogger, $rows, $logger)->record($subjectId);

        $this->assertSame([], $auditLogger->records);
        $this->assertSame([], $logger->records);
    }

    public function testALockThatFailsIsSwallowedAndLoggedLikeAFailedWrite(): void
    {
        // A lock timeout is one more way to lose the projection; on the login path it may never become a 500.
        $failure = new RuntimeException('lock timeout');
        $rows = new InMemoryIdentityRowLock();
        $rows->onLock = static function () use ($failure): never {
            throw $failure;
        };
        $auditLogger = new RecordingAuditLogger();
        $logger = new RecordingLogger();

        $this->recorder($auditLogger, $rows, $logger)->record(Uuid::generate());

        $this->assertSame([], $auditLogger->records);
        $this->assertCount(1, $logger->records);
        $this->assertSame($failure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testSwallowsAFailedAuditWriteAndLogsItAtError(): void
    {
        $failure = new RuntimeException('audit_log is unavailable');
        $logger = new RecordingLogger();

        (new RecordLockoutAuditBestEffort(
            new FailingAuditLogger($failure),
            InMemoryIdentityRowLock::serialiser(),
            $logger,
        ))->record(Uuid::generate());

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
        // The id is the personal datum this control handles. A brute-force run drives this path once per
        // lockout window, so naming the subject here would write a stream of person ids into the log the
        // erasure chain does not reach. (The swallowed exception's own message is outside this class's control.)
        $subjectId = Uuid::generate();
        $logger = new RecordingLogger();

        (new RecordLockoutAuditBestEffort(
            new FailingAuditLogger(),
            InMemoryIdentityRowLock::serialiser(),
            $logger,
        ))->record($subjectId);

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertStringNotContainsString($subjectId, $record['message']);
        $this->assertSame(['exception'], \array_keys($record['context']));
    }

    public function testALoggerFailureWhileReportingDoesNotEscape(): void
    {
        // The outer catch protects the audit write; nothing protected the report of that failure. A stream
        // handler failing its own I/O (a closed stderr pipe, an unwritable log dir) used to escape from
        // INSIDE the catch and abort whatever called record() — the scheduled tick, for the sibling
        // {@see RecordLockoutNoticeAuditBestEffortTest}, or the login-failure handler here.
        $this->expectNotToPerformAssertions();

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new RuntimeException('stderr pipe closed'));

        (new RecordLockoutAuditBestEffort(
            new FailingAuditLogger(),
            InMemoryIdentityRowLock::serialiser(),
            $logger,
        ))->record(Uuid::generate());
    }

    private function recorder(
        RecordingAuditLogger $auditLogger,
        InMemoryIdentityRowLock $rows,
        RecordingLogger $logger,
    ): RecordLockoutAuditBestEffort {
        return new RecordLockoutAuditBestEffort(
            $auditLogger,
            InMemoryIdentityRowLock::serialiser($rows),
            $logger,
        );
    }
}
