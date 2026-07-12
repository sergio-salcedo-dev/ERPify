<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\UserLocked;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
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

        $registrar = new LoginAttemptRegistrar(
            $repository,
            $eventBus,
            $transactionManager,
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );

        $registrar->clear(UserMother::DEFAULT_ID);

        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testRecordingAgainstAnAlreadyLockedIdentityOpensNoTransaction(): void
    {
        $repository = new InMemoryUserRepository($this->lockedUser());
        $eventBus = new RecordingEventBus();
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->never())->method('transactional');

        $registrar = new LoginAttemptRegistrar(
            $repository,
            $eventBus,
            $transactionManager,
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );

        $registrar->recordFailure(UserMother::DEFAULT_EMAIL);

        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    private function registrar(InMemoryUserRepository $repository, RecordingEventBus $eventBus): LoginAttemptRegistrar
    {
        return new LoginAttemptRegistrar(
            $repository,
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );
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
