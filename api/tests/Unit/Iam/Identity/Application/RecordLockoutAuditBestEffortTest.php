<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\RecordLockoutAuditBestEffort;
use Erpify\Iam\Identity\Application\ReportsAuditFailureSafely;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The swallow-and-log contract of the lockout projection, pinned on its own, and the serialisation that keeps
 * its row from outliving an erasure. {@see LoginAttemptRegistrarAuditTest} exercises the class through the use
 * case but only asserts the row and the commit boundary, so deleting the `logger->error` call and leaving a
 * bare `catch` left the whole suite green — the failure mode of a best-effort projection is silence, and
 * nothing else in the suite could see it.
 *
 * What a single-threaded double can say about the lock is its ORDER: the subject's row was locked before the
 * write, and was still held while it ran. That a rival erasure really waits on it is Postgres's to
 * prove, in {@see \Erpify\Tests\Functional\Iam\Identity\LateAuditWriterErasureSerialisationFunctionalTest}.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RecordLockoutAuditBestEffort::class)]
#[CoversTrait(ReportsAuditFailureSafely::class)]
final class RecordLockoutAuditBestEffortTest extends TestCase
{
    public function testPassesTheLockoutThroughToTheAuditLogger(): void
    {
        $identityRows = new InMemoryIdentityRowLock();
        $auditLogger = new RowLockAwareAuditLogger($identityRows);
        $logger = new RecordingLogger();

        $this->recorder($auditLogger, $identityRows, $logger)->record(UserMother::DEFAULT_ID);

        $this->assertCount(1, $auditLogger->records);
        $record = $auditLogger->records[0];
        $this->assertSame('USER_LOCKED', $record['action']);
        $this->assertSame(AuditLevel::SECURITY, $record['level']);

        $resource = $record['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame(UserMother::DEFAULT_ID, $resource->id);
        $this->assertSame([], $record['metadata'], 'Metadata would reach json_encode on a path that cannot throw.');
        $this->assertSame([], $logger->records, 'A successful projection must not log.');
    }

    public function testTheRowIsWrittenWhileTheSubjectRowIsHeld(): void
    {
        // An unlocked write is exactly as green on "a row was written" as a locked one, and that difference is
        // the whole of what keeps the row from committing after an erasure's pass over the trail.
        $identityRows = new InMemoryIdentityRowLock(new InMemoryUserRepository(UserMother::create()));
        $auditLogger = new RowLockAwareAuditLogger($identityRows);
        $rowsWhenLocked = null;
        $identityRows->onLock = static function () use ($auditLogger, &$rowsWhenLocked): void {
            $rowsWhenLocked = \count($auditLogger->records);
        };

        $this->recorder($auditLogger, $identityRows)->record(UserMother::DEFAULT_ID);

        $this->assertSame([UserMother::DEFAULT_ID], $identityRows->lockRequests, "The lock names the subject's row.");
        $this->assertSame(0, $rowsWhenLocked, 'The subject row is locked before the audit row is written.');
        $this->assertCount(1, $auditLogger->records);
        $this->assertTrue($auditLogger->records[0]['held'], 'The write runs while the lock is still held.');
    }

    public function testAnIdentityGoneUnderTheLockIsOwedNoRow(): void
    {
        // Seen absent under the lock means an erasure committed first and has already run its pass over the
        // trail: a row written now would name the subject with nothing left to redact it.
        $identityRows = new InMemoryIdentityRowLock(new InMemoryUserRepository(UserMother::create()));
        $identityRows->goneUnderLock = true;

        $auditLogger = new RowLockAwareAuditLogger($identityRows);
        $logger = new RecordingLogger();

        $this->recorder($auditLogger, $identityRows, $logger)->record(UserMother::DEFAULT_ID);

        $this->assertSame([UserMother::DEFAULT_ID], $identityRows->lockRequests);
        $this->assertSame([], $auditLogger->records);
        $this->assertCount(1, $logger->records, 'The withheld row is still explained.');
        $this->assertSame(LogLevel::INFO, $logger->records[0]['level'], 'An erased subject is not a failure.');
        $this->assertSame(['phase' => 'subject_absent'], $logger->records[0]['context'], 'It names no subject.');
    }

    public function testSwallowsAFailedAuditWriteAndLogsItAtError(): void
    {
        $failure = new RuntimeException('audit_log is unavailable');
        $logger = new RecordingLogger();

        $this->recorder(new FailingAuditLogger($failure), logger: $logger)->record(UserMother::DEFAULT_ID);

        $this->assertCount(
            1,
            $logger->records,
            'A swallowed projection failure that logs nothing is an effect that silently did not happen.',
        );
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame('write', $logger->records[0]['context']['phase'] ?? null);
        $this->assertSame($failure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testALockThatFailsIsSwallowedAndReportedAsTheLockPhase(): void
    {
        // A lock wait that times out (`55P03`), a deadlock or BEGIN itself surfaces before any write — outside
        // the swallow it would be a 500 on exactly the tenth failed attempt of a resolved identity — and the
        // report has to say it was the lock, since the message is the same whatever failed.
        $failure = new RuntimeException('canceling statement due to lock timeout');
        $identityRows = new InMemoryIdentityRowLock();
        $identityRows->onLock = static fn () => throw $failure;

        $auditLogger = new RowLockAwareAuditLogger($identityRows);
        $logger = new RecordingLogger();

        $this->recorder($auditLogger, $identityRows, $logger)->record(UserMother::DEFAULT_ID);

        $this->assertSame([], $auditLogger->records);
        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame('lock', $logger->records[0]['context']['phase'] ?? null);
        $this->assertSame($failure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testTheLogLineNamesNoSubject(): void
    {
        // The id is the personal datum this control handles. A brute-force run drives this path once per
        // lockout window, so naming the subject here would write a stream of person ids into the log the
        // erasure chain does not reach. (The swallowed exception's own message is outside this class's control.)
        $logger = new RecordingLogger();

        $this->recorder(new FailingAuditLogger(), logger: $logger)->record(UserMother::DEFAULT_ID);

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertStringNotContainsString(UserMother::DEFAULT_ID, $record['message']);
        $this->assertSame(['phase', 'exception'], \array_keys($record['context']));
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

        $this->recorder(new FailingAuditLogger(), logger: $logger)->record(UserMother::DEFAULT_ID);
    }

    private function recorder(
        AuditLogger $auditLogger,
        ?InMemoryIdentityRowLock $identityRows = null,
        ?LoggerInterface $logger = null,
    ): RecordLockoutAuditBestEffort {
        return new RecordLockoutAuditBestEffort(
            $identityRows ?? new InMemoryIdentityRowLock(),
            $auditLogger,
            $logger ?? new RecordingLogger(),
        );
    }
}
