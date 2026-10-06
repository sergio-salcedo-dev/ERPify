<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use ArrayObject;
use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The recovery-throttle projection against the identity erasure: the address is resolved under the subject's
 * row lock, inside the transaction that writes the row, and an address whose identity vanished under that
 * lock is recorded like one that never named anybody.
 *
 * An unlocked lookup would let an erasure finish its trail passes between resolving the address and writing
 * the row, which would then name an identity that no longer exists, with nothing left to rewrite it.
 *
 * @internal
 */
#[CoversClass(RecordRecoveryThrottleAuditBestEffort::class)]
final class RecordRecoveryThrottleAuditSerialisationTest extends TestCase
{
    public function testTheSubjectIsResolvedUnderItsRowLockInsideTheTransactionThatWrites(): void
    {
        $transactions = new InlineTransactionManager();
        $users = new InMemoryUserRepository(UserMother::create());
        /** @var ArrayObject<int, array{string, bool}> $journal */
        $journal = new ArrayObject();
        $users->onFindByEmailForUpdate = static function () use ($journal, $transactions): void {
            $journal->append(['lock', $transactions->inside]);
        };
        $recorded = new RecordingAuditLogger();

        $this->recorder($users, new TransactionObservingAuditLogger($recorded, $journal, $transactions), $transactions)
            ->record(UserMother::DEFAULT_EMAIL)
        ;

        $this->assertSame([['lock', true], ['write', true]], $journal->getArrayCopy());
        $this->assertTrue($transactions->committed);
        $this->assertInstanceOf(AuditResource::class, $recorded->records[0]['resource'] ?? null);
    }

    public function testASubjectErasedWhileTheLockWaitedIsNotNamed(): void
    {
        // Once the erasure commits, the address names nobody, and the row is the one an unknown address gets:
        // nothing an observer could tell apart from an identity that never existed.
        $users = new InMemoryUserRepository(UserMother::create());
        $users->goneUnderLock = true;

        $auditLogger = new RecordingAuditLogger();

        $this->recorder($users, $auditLogger, new InlineTransactionManager())->record(UserMother::DEFAULT_EMAIL);

        $this->assertCount(1, $auditLogger->records);
        $this->assertNotInstanceOf(AuditResource::class, $auditLogger->records[0]['resource']);
    }

    private function recorder(
        InMemoryUserRepository $users,
        AuditLogger $auditLogger,
        InlineTransactionManager $transactions,
    ): RecordRecoveryThrottleAuditBestEffort {
        return new RecordRecoveryThrottleAuditBestEffort(
            new FixedRecoveryThrottleAuditBudget(granted: true),
            $users,
            $auditLogger,
            $transactions,
            new RecordingLogger(),
        );
    }
}
