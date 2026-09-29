<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * How the recovery-throttle projection serialises on the subject's `identity_user` row: the lookup is the locked
 * one, the write runs while it is held, and the budget is decided before the lock is asked for.
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
    public function testASpentBudgetTakesNoLock(): void
    {
        // The claim is decided ahead of the lock: a refused request past the budget must cost neither a
        // BEGIN/COMMIT nor a wait on the subject's row, or the budget would stop bounding what a sweep can force.
        $identityRows = new InMemoryIdentityRowLock(new InMemoryUserRepository(UserMother::create()));

        $this->recorder(new RecordingAuditLogger(), $identityRows, new FixedRecoveryThrottleAuditBudget(granted: false))
            ->record(UserMother::DEFAULT_EMAIL)
        ;

        $this->assertSame(0, $identityRows->transactionsOpened);
        $this->assertSame([], $identityRows->lockRequests);
    }

    public function testTheSubjectIsResolvedUnderItsRowLockAndWrittenWhileItIsHeld(): void
    {
        // An unlocked lookup reads just as green on "the row names the subject", and it is the one that lets an
        // erasure commit between the answer and the INSERT, leaving the real id behind its pass over the trail.
        $identityRows = new InMemoryIdentityRowLock(new InMemoryUserRepository(UserMother::create()));
        $auditLogger = new RowLockAwareAuditLogger($identityRows);
        $rowsWhenLocked = null;
        $identityRows->onLock = static function () use ($auditLogger, &$rowsWhenLocked): void {
            $rowsWhenLocked = \count($auditLogger->records);
        };

        $this->recorder($auditLogger, $identityRows)->record(UserMother::DEFAULT_EMAIL);

        $this->assertSame([UserMother::DEFAULT_EMAIL], $identityRows->lockRequests, 'The lock resolves the address.');
        $this->assertSame(0, $rowsWhenLocked, 'The subject row is locked before the audit row is written.');
        $this->assertCount(1, $auditLogger->records);
        $this->assertTrue($auditLogger->records[0]['held'], 'The write runs while the lock is still held.');
        $resource = $auditLogger->records[0]['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame(UserMother::DEFAULT_ID, $resource->id);
    }

    public function testAnIdentityGoneUnderTheLockIsRecordedWithoutAResource(): void
    {
        // Absent under the lock means an erasure committed first: the row still reports the throttle, exactly as
        // for an address that never named anyone, and names nobody its pass can no longer reach.
        $identityRows = new InMemoryIdentityRowLock(new InMemoryUserRepository(UserMother::create()));
        $identityRows->goneUnderLock = true;

        $auditLogger = new RecordingAuditLogger();

        $this->recorder($auditLogger, $identityRows)->record(UserMother::DEFAULT_EMAIL);

        $this->assertCount(1, $auditLogger->records);
        $this->assertNotInstanceOf(AuditResource::class, $auditLogger->records[0]['resource']);
    }

    public function testAnAddressWithNoCanonicalFormTakesNoLock(): void
    {
        // A blank address can name no row, so there is nothing to serialise on: the resource-less row is written
        // without a transaction it could never have needed.
        $identityRows = new InMemoryIdentityRowLock(new InMemoryUserRepository(UserMother::create()));
        $auditLogger = new RecordingAuditLogger();

        $this->recorder($auditLogger, $identityRows)->record('   ');

        $this->assertSame(0, $identityRows->transactionsOpened);
        $this->assertCount(1, $auditLogger->records);
    }

    public function testALockThatFailsIsSwallowedAndReportedAsTheLockPhase(): void
    {
        // A lock wait that times out behind an erasure (`55P03`) surfaces before the write; on a
        // `kernel.terminate` listener it would otherwise escape after the uniform 202 was already sent.
        $failure = new RuntimeException('canceling statement due to lock timeout');
        $identityRows = new InMemoryIdentityRowLock(new InMemoryUserRepository(UserMother::create()));
        $identityRows->onLock = static fn () => throw $failure;

        $auditLogger = new RecordingAuditLogger();
        $logger = new RecordingLogger();

        $this->recorder($auditLogger, $identityRows, logger: $logger)->record(UserMother::DEFAULT_EMAIL);

        $this->assertSame([], $auditLogger->records);
        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame(LogLevel::ERROR, $record['level']);
        $this->assertSame(['phase', 'exception'], \array_keys($record['context']));
        $this->assertSame('lock', $record['context']['phase'] ?? null);
        $this->assertSame($failure, $record['context']['exception'] ?? null);
        $this->assertStringNotContainsStringIgnoringCase(UserMother::DEFAULT_EMAIL, $record['message']);
        $this->assertStringNotContainsString(UserMother::DEFAULT_ID, $record['message']);
    }

    private function recorder(
        AuditLogger $auditLogger,
        InMemoryIdentityRowLock $identityRows,
        ?FixedRecoveryThrottleAuditBudget $budget = null,
        ?RecordingLogger $logger = null,
    ): RecordRecoveryThrottleAuditBestEffort {
        return new RecordRecoveryThrottleAuditBestEffort(
            $budget ?? new FixedRecoveryThrottleAuditBudget(granted: true),
            $identityRows,
            $auditLogger,
            $logger ?? new RecordingLogger(),
        );
    }
}
