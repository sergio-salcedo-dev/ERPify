<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\UserLocked;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The registrar owns the persisted lockout counter: a failed attempt increments it (and trips the lock at the
 * threshold), a successful login clears it. It resolves failures BY EMAIL and no-ops on an unknown or malformed
 * one, so a failure against a non-existent account writes nothing and emits nothing.
 *
 * @internal
 */
#[CoversClass(LoginAttemptRegistrar::class)]
final class LoginAttemptRegistrarTest extends TestCase
{
    use BuildsLockoutRegistrar;

    private const string NOW = '2026-07-11T12:00:00+00:00';

    public function testRecordsAFailedAttemptForAKnownEmailWithoutLockingBelowTheThreshold(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();

        $this->registrar($repository, $eventBus)->recordFailure(UserMother::DEFAULT_EMAIL);

        $this->assertSame([$user], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertFalse($user->isLockedAt(new DateTimeImmutable(self::NOW)));
    }

    public function testAnUnknownEmailIsANoOpThatNeitherWritesNorEmits(): void
    {
        $repository = new InMemoryUserRepository();
        $eventBus = new RecordingEventBus();

        $this->registrar($repository, $eventBus)->recordFailure('nobody@erpify.test');

        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testAMalformedEmailIsANoOp(): void
    {
        $repository = new InMemoryUserRepository();
        $eventBus = new RecordingEventBus();

        $this->registrar($repository, $eventBus)->recordFailure('   ');

        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testCrossingTheThresholdPublishesExactlyOneUserLocked(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $registrar = $this->registrar($repository, $eventBus);

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $registrar->recordFailure(UserMother::DEFAULT_EMAIL);
        }

        $this->assertTrue($user->isLockedAt(new DateTimeImmutable(self::NOW)));
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(UserLocked::class, $eventBus->publishedEvents[0]);
    }

    public function testClearingTheLockoutForAnAuthenticatedUserResetsIt(): void
    {
        $user = $this->lockedUser();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();

        $this->registrar($repository, $eventBus)->clear(UserMother::DEFAULT_ID);

        $this->assertContains($user, $repository->saved);
        $this->assertFalse($user->isLockedAt(new DateTimeImmutable(self::NOW)));
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testClearingIsANoOpForAnUnknownId(): void
    {
        $repository = new InMemoryUserRepository();
        $eventBus = new RecordingEventBus();

        $this->registrar($repository, $eventBus)->clear(UserMother::DEFAULT_ID);

        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testClearingAnAlreadyCleanIdentityOpensNoTransactionOnTheHotLoginPath(): void
    {
        $repository = new InMemoryUserRepository(UserMother::create());
        $eventBus = new RecordingEventBus();
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->never())->method('transactional');

        $registrar = $this->registrarWith($repository, $eventBus, $transactionManager);

        $registrar->clear(UserMother::DEFAULT_ID);

        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testRecordingAgainstAnAlreadyLockedIdentityWritesNothingButStillTakesTheLock(): void
    {
        // The skip moved INSIDE the transaction, and the assertion moved with it. It used to read "opens no
        // transaction", decided from an unlocked snapshot — which is the read this path may no longer trust:
        // a redemption or an administrative unlock commits between that read and the write, and the write
        // restores `locked_until` from state that no longer exists. What survives, and is what actually
        // mattered, is that a sustained attack on a locked account produces NO write and NO event.
        $repository = new InMemoryUserRepository($this->lockedUser());
        $journal = new LockOrderJournal();
        $repository->lockOrderJournal = $journal;
        $eventBus = new RecordingEventBus();

        $registrar = $this->registrar($repository, $eventBus);

        $registrar->recordFailure(UserMother::DEFAULT_EMAIL);

        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame(
            [LockOrderJournal::IDENTITY_USER],
            $journal->crossTableOrder(),
            'the decision must be taken on the LOCKED row, or the refusal is as untrustworthy as the write',
        );
    }

    public function testAnUnknownAddressOpensNoTransactionAtAll(): void
    {
        // The one fast path that survives, and the reason it does: an address that resolves to no row must
        // leave nothing behind that could tell it apart from one that does — no write, no event, and not even
        // a BEGIN. Existence is also the only thing an unlocked read may conclude here, since a row that
        // exists cannot stop existing under the attack this path is counting.
        $repository = new InMemoryUserRepository();
        $eventBus = new RecordingEventBus();
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->never())->method('transactional');

        $registrar = $this->registrarWith($repository, $eventBus, $transactionManager);

        $registrar->recordFailure('nobody@erpify.test');

        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testAConcurrentUnlockCommittingUnderTheLockIsNotUndoneByTheAttemptInFlight(): void
    {
        // The race step 5 exists to close, staged at the only instant it can happen: the identity is one
        // attempt short of the threshold, an attacker's failure is in flight, and the recovery-secret
        // redemption (or an administrator) clears the lockout in between. Deciding on the UNLOCKED snapshot
        // takes the counter 9 -> 10 and trips the lock, writing `locked_until` back over the clear that just
        // committed — which is exactly the state the redemption exists to leave, undone by the attack it was
        // recovering from. Deciding on the LOCKED row starts from the cleared counter instead.
        $user = $this->oneAttemptFromTheThreshold();
        $repository = new InMemoryUserRepository($user);
        // Stands in for the rival transaction committing: by the time the locked read returns, the row it
        // hydrates is the cleared one.
        $repository->onFindByEmailForUpdate = static function () use ($user): void {
            $user->clearLockout();
        };
        $eventBus = new RecordingEventBus();

        $this->registrar($repository, $eventBus)->recordFailure(UserMother::DEFAULT_EMAIL);

        $this->assertCount(1, $repository->saved);
        $this->assertFalse(
            $repository->saved[0]->isLockedAt(new DateTimeImmutable(self::NOW)),
            'the unlock that committed under the lock was overwritten by an attempt decided before it',
        );
        $this->assertSame(
            [],
            $eventBus->publishedEvents,
            'a UserLocked was raised for a lock that must not have been reached',
        );
    }

    private function registrar(InMemoryUserRepository $repository, RecordingEventBus $eventBus): LoginAttemptRegistrar
    {
        return $this->registrarWith($repository, $eventBus, new InlineTransactionManager());
    }

    /**
     * One failed attempt short of tripping the lock — the only starting state in which a single further
     * attempt decides between "locked" and "not locked", and therefore the only one where reading the
     * counter unlocked changes the outcome rather than merely the number.
     */
    private function oneAttemptFromTheThreshold(): User
    {
        $user = UserMother::create();
        $now = new DateTimeImmutable(self::NOW);

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS - 1; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        $user->pullDomainEvents();

        return $user;
    }

    private function lockedUser(): User
    {
        $user = UserMother::create();
        $now = new DateTimeImmutable(self::NOW);

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        $user->pullDomainEvents();

        return $user;
    }
}
