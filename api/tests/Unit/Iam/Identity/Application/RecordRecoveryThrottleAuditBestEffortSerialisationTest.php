<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * How the recovery-throttle projection serialises on the subject's `identity_user` row: the lookup is the locked
 * one, it runs inside the unit of work that writes, and the budget is decided before that unit of work opens.
 *
 * Split from {@see RecordRecoveryThrottleAuditBestEffortTest}, which pins what the row says and the
 * swallow-and-log contract; this pins WHEN the row may be written relative to an erasure. What a single-threaded
 * double can say is the order; that a rival erasure really waits is Postgres's to prove, in
 * {@see \Erpify\Tests\Functional\Iam\Identity\LateAuditWriterErasureSerialisationFunctionalTest}.
 *
 * @internal
 */
#[CoversClass(RecordRecoveryThrottleAuditBestEffort::class)]
final class RecordRecoveryThrottleAuditBestEffortSerialisationTest extends TestCase
{
    public function testASpentBudgetOpensNoTransactionAndTakesNoLock(): void
    {
        // The claim is decided ahead of the unit of work: a refused request past the budget must cost neither a
        // BEGIN/COMMIT nor a wait on the subject's row, or the budget would stop bounding what a sweep can force.
        $transactionManager = new InlineTransactionManager();
        $users = new InMemoryUserRepository(UserMother::create());
        $lockTaken = false;
        $users->onFindByEmailForUpdate = static function () use (&$lockTaken): void {
            $lockTaken = true;
        };

        (new RecordRecoveryThrottleAuditBestEffort(
            new FixedRecoveryThrottleAuditBudget(granted: false),
            $users,
            $transactionManager,
            new RecordingAuditLogger(),
            new RecordingLogger(),
        ))->record(UserMother::DEFAULT_EMAIL);

        $this->assertFalse($transactionManager->committed);
        $this->assertFalse($lockTaken);
    }

    public function testTheSubjectIsResolvedUnderItsRowLockInsideTheWritesUnitOfWork(): void
    {
        // An unlocked lookup reads just as green on "the row names the subject", and it is the one that lets an
        // erasure commit between the answer and the INSERT, leaving the real id behind its pass over the trail.
        $transactionManager = new InlineTransactionManager();
        $auditLogger = new TransactionAwareAuditLogger($transactionManager);
        $users = new InMemoryUserRepository(UserMother::create());
        $lockedInside = null;
        $rowsWhenLocked = null;
        $users->onFindByEmailForUpdate = static function () use (
            $transactionManager,
            $auditLogger,
            &$lockedInside,
            &$rowsWhenLocked,
        ): void {
            $lockedInside = $transactionManager->inside;
            $rowsWhenLocked = \count($auditLogger->records);
        };

        (new RecordRecoveryThrottleAuditBestEffort(
            new FixedRecoveryThrottleAuditBudget(granted: true),
            $users,
            $transactionManager,
            $auditLogger,
            new RecordingLogger(),
        ))->record(UserMother::DEFAULT_EMAIL);

        $this->assertTrue($lockedInside, 'The row lock is taken inside the unit of work that writes.');
        $this->assertSame(0, $rowsWhenLocked, 'The subject row is locked before the audit row is written.');
        $this->assertCount(1, $auditLogger->records);
        $this->assertTrue($auditLogger->records[0]['inside']);
        $resource = $auditLogger->records[0]['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame(UserMother::DEFAULT_ID, $resource->id);
    }

    public function testAnIdentityGoneUnderTheLockIsRecordedWithoutAResource(): void
    {
        // Absent under the lock means an erasure committed first: the row still reports the throttle, exactly as
        // for an address that never named anyone, and names nobody its pass can no longer reach.
        $users = new InMemoryUserRepository(UserMother::create());
        $users->goneUnderLock = true;

        $auditLogger = new RecordingAuditLogger();

        (new RecordRecoveryThrottleAuditBestEffort(
            new FixedRecoveryThrottleAuditBudget(granted: true),
            $users,
            new InlineTransactionManager(),
            $auditLogger,
            new RecordingLogger(),
        ))->record(UserMother::DEFAULT_EMAIL);

        $this->assertCount(1, $auditLogger->records);
        $this->assertNotInstanceOf(AuditResource::class, $auditLogger->records[0]['resource']);
    }

    public function testALockThatFailsIsSwallowedAndLoggedAtError(): void
    {
        // A lock wait that times out behind an erasure (`55P03`) surfaces from the lookup, before the write; on
        // a `kernel.terminate` listener it would otherwise escape after the uniform 202 was already sent.
        $failure = new RuntimeException('canceling statement due to lock timeout');
        $users = new InMemoryUserRepository(UserMother::create());
        $users->onFindByEmailForUpdate = static fn () => throw $failure;

        $auditLogger = new RecordingAuditLogger();
        $logger = new RecordingLogger();

        (new RecordRecoveryThrottleAuditBestEffort(
            new FixedRecoveryThrottleAuditBudget(granted: true),
            $users,
            new InlineTransactionManager(),
            $auditLogger,
            $logger,
        ))->record(UserMother::DEFAULT_EMAIL);

        $this->assertSame([], $auditLogger->records);
        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame($failure, $logger->records[0]['context']['exception'] ?? null);
        $this->assertStringNotContainsStringIgnoringCase(UserMother::DEFAULT_EMAIL, $logger->records[0]['message']);
    }

    public function testATransactionThatFailsIsSwallowedAndLoggedAtError(): void
    {
        // BEGIN, COMMIT or a translated deadlock fails outside the lookup and the write alike; on a
        // `kernel.terminate` listener it would otherwise escape after the uniform 202 was already sent.
        $failure = new RuntimeException('could not begin a transaction');
        $transactionManager = $this->createStub(TransactionManager::class);
        $transactionManager->method('transactional')->willThrowException($failure);
        $auditLogger = new RecordingAuditLogger();
        $logger = new RecordingLogger();

        (new RecordRecoveryThrottleAuditBestEffort(
            new FixedRecoveryThrottleAuditBudget(granted: true),
            new InMemoryUserRepository(UserMother::create()),
            $transactionManager,
            $auditLogger,
            $logger,
        ))->record(UserMother::DEFAULT_EMAIL);

        $this->assertSame([], $auditLogger->records);
        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame(LogLevel::ERROR, $record['level']);
        $this->assertSame(['exception'], \array_keys($record['context']));
        $this->assertSame($failure, $record['context']['exception'] ?? null);
        $this->assertStringNotContainsStringIgnoringCase(UserMother::DEFAULT_EMAIL, $record['message']);
        $this->assertStringNotContainsString(UserMother::DEFAULT_ID, $record['message']);
    }
}
