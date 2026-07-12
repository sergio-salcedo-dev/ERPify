<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use DateInterval;
use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Infrastructure\Security\DeactivatedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\InvitedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\LockedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Identity\Infrastructure\Security\SuspendedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\UserChecker;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @internal
 */
#[CoversClass(UserChecker::class)]
final class UserCheckerTest extends TestCase
{
    private const string NOW = '2026-07-11T12:00:00+00:00';

    public function testPreAuthRejectsAnInvitedIdentityBeforeAnyPasswordCheck(): void
    {
        $this->expectException(InvitedAccountException::class);

        $this->checker()->checkPreAuth(new SecurityUser(UserMother::invited()));
    }

    public function testPreAuthAdmitsAnActiveIdentity(): void
    {
        $this->expectNotToPerformAssertions();

        $this->checker()->checkPreAuth(new SecurityUser(UserMother::create()));
    }

    public function testPostAuthRaisesTheSuspendedWall(): void
    {
        $this->expectException(SuspendedAccountException::class);

        $this->checker()->checkPostAuth(new SecurityUser($this->suspended()));
    }

    public function testPostAuthRaisesTheDeactivatedWall(): void
    {
        $this->expectException(DeactivatedAccountException::class);

        $this->checker()->checkPostAuth(new SecurityUser($this->deactivated()));
    }

    public function testPostAuthRaisesTheLockedWallForALockedActiveIdentity(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $this->expectException(LockedAccountException::class);

        $this->checker($now)->checkPostAuth(new SecurityUser($this->lockedActiveUser($now)));
    }

    public function testPostAuthAdmitsAnActiveIdentityWhoseLockHasLapsed(): void
    {
        $this->expectNotToPerformAssertions();

        $lockedAt = new DateTimeImmutable(self::NOW);
        $afterExpiry = $lockedAt->add(new DateInterval('PT16M'));

        $this->checker($afterExpiry)->checkPostAuth(new SecurityUser($this->lockedActiveUser($lockedAt)));
    }

    public function testPostAuthRaisesTheSuspendedWallEvenForALockedIdentity(): void
    {
        // Precedence: the lifecycle wall wins over the transient lockout, so `SUSPENDED + locked` never
        // surfaces as `locked` — the state arm runs before the lock arm.
        $now = new DateTimeImmutable(self::NOW);
        $user = $this->lockedActiveUser($now);
        $user->suspend();

        $this->expectException(SuspendedAccountException::class);

        $this->checker($now)->checkPostAuth(new SecurityUser($user));
    }

    public function testPostAuthAdmitsAnActiveIdentity(): void
    {
        $this->expectNotToPerformAssertions();

        $this->checker()->checkPostAuth(new SecurityUser(UserMother::create()));
    }

    public function testIgnoresAUserItDoesNotOwn(): void
    {
        $this->expectNotToPerformAssertions();

        $checker = $this->checker();
        $foreign = $this->createStub(UserInterface::class);

        $checker->checkPreAuth($foreign);
        $checker->checkPostAuth($foreign);
    }

    private function checker(?DateTimeImmutable $now = null): UserChecker
    {
        return new UserChecker(new FixedClock($now ?? new DateTimeImmutable(self::NOW)));
    }

    private function suspended(): User
    {
        $user = UserMother::create();
        $user->suspend();

        return $user;
    }

    private function deactivated(): User
    {
        $user = UserMother::create();
        $user->deactivate();

        return $user;
    }

    private function lockedActiveUser(DateTimeImmutable $now): User
    {
        $user = UserMother::create();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        return $user;
    }
}
