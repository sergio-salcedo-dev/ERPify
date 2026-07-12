<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\UserLocked;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lockout is a timestamp gate on the aggregate, orthogonal to the {@see IdentityStatus} lifecycle: a
 * locked identity stays `ACTIVE`. These drive that behaviour through {@see User::recordFailedAttempt()},
 * {@see User::clearLockout()} and {@see User::isLockedAt()}, reading the outcome via the recorded domain events
 * and the lock predicate (the counter itself is private, by design).
 *
 * @internal
 */
#[CoversClass(User::class)]
final class UserLockoutTest extends TestCase
{
    private const string NOW = '2026-07-11T12:00:00+00:00';

    #[Test]
    public function crossingTheThresholdLocksAndRecordsUserLockedExactlyOnce(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $user = UserMother::create();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        $this->assertTrue($user->isLockedAt($now));

        $events = $user->pullDomainEvents();
        $this->assertCount(1, $events);
        $locked = $events[0];
        $this->assertInstanceOf(UserLocked::class, $locked);
        $this->assertSame(UserMother::DEFAULT_ID, $locked->aggregateId());
        $this->assertSame(
            $now->add(new DateInterval(User::LOCK_DURATION))->format(DateTimeInterface::ATOM),
            $locked->lockedUntil()->format(DateTimeInterface::ATOM),
        );
    }

    #[Test]
    public function stayingBelowTheThresholdNeitherLocksNorRecordsAnEvent(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $user = UserMother::create();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS - 1; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        $this->assertFalse($user->isLockedAt($now));
        $this->assertSame([], $user->pullDomainEvents());
    }

    #[Test]
    public function anAlreadyLockedIdentityIgnoresFurtherAttemptsWithNoReEmit(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $user = $this->lockedAt($now);
        $user->pullDomainEvents();

        $user->recordFailedAttempt($now);
        $user->recordFailedAttempt($now);

        $this->assertTrue($user->isLockedAt($now));
        $this->assertSame([], $user->pullDomainEvents());
    }

    #[Test]
    public function theLockHoldsUntilItsExpiryAndReleasesAtTheBoundary(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $user = $this->lockedAt($now);

        $this->assertTrue($user->isLockedAt($now->add(new DateInterval('PT14M'))));
        $this->assertFalse($user->isLockedAt($now->add(new DateInterval(User::LOCK_DURATION))));
        $this->assertFalse($user->isLockedAt($now->add(new DateInterval('PT16M'))));
    }

    #[Test]
    public function aLapsedLockReadsAsUnlockedWithoutAnyClear(): void
    {
        $past = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $user = $this->lockedAt($past);

        $this->assertFalse($user->isLockedAt(new DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function clearingTheLockoutResetsTheStateAndIsIdempotent(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $user = $this->lockedAt($now);
        $user->pullDomainEvents();

        $this->assertTrue($user->clearLockout());
        $this->assertFalse($user->isLockedAt($now));

        $this->assertFalse($user->clearLockout());
        $this->assertFalse($user->isLockedAt($now));

        $user->recordFailedAttempt($now);
        $this->assertFalse($user->isLockedAt($now));
        $this->assertSame([], $user->pullDomainEvents());
    }

    #[Test]
    public function aNonActiveIdentityNeverAccruesALockout(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $suspended = UserMother::create();
        $suspended->suspend();
        $suspended->pullDomainEvents();

        foreach ([UserMother::invited(), $suspended] as $user) {
            for ($attempt = 0; $attempt <= User::MAX_FAILED_ATTEMPTS; ++$attempt) {
                $user->recordFailedAttempt($now);
            }

            $this->assertFalse($user->isLockedAt($now));
            $this->assertSame([], $user->pullDomainEvents());
        }
    }

    #[Test]
    public function aLapsedLockRestartsTheCountRatherThanReLockingOnTheNextMiss(): void
    {
        $lockedAt = new DateTimeImmutable(self::NOW);
        $user = $this->lockedAt($lockedAt);
        $user->pullDomainEvents();

        $afterExpiry = $lockedAt->add(new DateInterval('PT16M'));

        $user->recordFailedAttempt($afterExpiry);
        $this->assertFalse($user->isLockedAt($afterExpiry));
        $this->assertSame([], $user->pullDomainEvents());

        for ($attempt = 1; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($afterExpiry);
        }

        $this->assertTrue($user->isLockedAt($afterExpiry));
        $this->assertCount(1, $user->pullDomainEvents());
    }

    private function lockedAt(DateTimeImmutable $now): User
    {
        $user = UserMother::create();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        return $user;
    }
}
