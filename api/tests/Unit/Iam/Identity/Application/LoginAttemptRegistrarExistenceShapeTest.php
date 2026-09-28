<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Whether an address exists may decide what a failed login WRITES, never the round trips it issues. These pin the
 * shape of the work a known and an unknown address pay, since that shape is observable from outside as latency.
 *
 * @internal
 */
#[CoversClass(LoginAttemptRegistrar::class)]
final class LoginAttemptRegistrarExistenceShapeTest extends TestCase
{
    use BuildsLockoutRegistrar;

    public function testAnUnknownAddressPaysTheSameTransactionAndLockedReadAsAKnownOne(): void
    {
        // Existence must not decide the SHAPE of this path, only what it writes. A branch that skipped the
        // transaction for an address resolving to no row would make BEGIN + `SELECT … FOR UPDATE` + COMMIT an
        // existence signal measurable from outside, so the unknown branch takes the same unit of work, runs
        // the same locked read INSIDE it, finds nothing and commits without writing.
        $repository = new InMemoryUserRepository();
        $journal = new LockOrderJournal();
        $repository->lockOrderJournal = $journal;
        $eventBus = new RecordingEventBus();
        $transactionManager = new InlineTransactionManager();
        $readUnderTheTransaction = null;
        $repository->onFindByEmailForUpdate = static function () use (
            $transactionManager,
            &$readUnderTheTransaction,
        ): void {
            $readUnderTheTransaction = $transactionManager->inside;
        };

        $registrar = $this->registrarWith($repository, $eventBus, $transactionManager);

        $registrar->recordFailure('nobody@erpify.test');

        $this->assertTrue($transactionManager->committed, 'an unknown address must open and commit the transaction');
        $this->assertSame(
            [LockOrderJournal::IDENTITY_USER],
            $journal->tablesLockedInOrder,
            'an unknown address must run the same locked read a known one does, exactly once',
        );
        $this->assertTrue($readUnderTheTransaction, 'the locked read must run inside the transaction, not before it');
        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testAKnownAndAnUnknownAddressTakeTheSameLockedReadsInTheSameTransactions(): void
    {
        // The pair the timing differential is measured over, compared on the round trips each one issues: both
        // must open one transaction and take one locked read. What is allowed to differ is only the write an
        // `ACTIVE` counter needs, which is the stated residual.
        $known = $this->portCallsFor(new InMemoryUserRepository($this->lockedUser()), UserMother::DEFAULT_EMAIL);
        $unknown = $this->portCallsFor(new InMemoryUserRepository(), 'nobody@erpify.test');

        $this->assertSame($known, $unknown);
        $this->assertSame([1, [LockOrderJournal::IDENTITY_USER]], $unknown);
    }

    /**
     * The transactions opened and the locks taken by one failed attempt against `$email`.
     *
     * @return array{int, list<string>}
     */
    private function portCallsFor(InMemoryUserRepository $repository, string $email): array
    {
        $journal = new LockOrderJournal();
        $repository->lockOrderJournal = $journal;
        $transactions = 0;
        $transactionManager = $this->createStub(TransactionManager::class);
        $transactionManager->method('transactional')->willReturnCallback(
            static function (callable $operation) use (&$transactions): mixed {
                ++$transactions;

                return $operation();
            },
        );

        $this->registrarWith($repository, new RecordingEventBus(), $transactionManager)->recordFailure($email);

        return [$transactions, $journal->tablesLockedInOrder];
    }

    private function lockedUser(): User
    {
        $user = UserMother::create();
        $now = self::lockoutProbeInstant();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        $user->pullDomainEvents();

        return $user;
    }
}
