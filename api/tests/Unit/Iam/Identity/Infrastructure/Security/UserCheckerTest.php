<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Closure;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Infrastructure\Security\DeactivatedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\InvitedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\RevokedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Identity\Infrastructure\Security\SuspendedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\UserChecker;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\CountingPreIdentityTimingFloor;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The admission contract: every non-`ACTIVE` status is refused BEFORE the password is verified, pays the timing
 * floor, and raises a plain account-status exception the authenticator collapses into the uniform 401. Only an
 * `ACTIVE` identity reaches a password check, and nothing is left for after it.
 *
 * @internal
 */
#[CoversClass(UserChecker::class)]
final class UserCheckerTest extends TestCase
{
    private const string NOW = '2026-07-11T12:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
    }

    /**
     * @param Closure(): User                       $identity
     * @param class-string<AccountStatusException> $wall
     */
    #[DataProvider('provideEveryNonActiveStatusIsWalledBeforeVerificationAndPaysTheFloorCases')]
    public function testEveryNonActiveStatusIsWalledBeforeVerificationAndPaysTheFloor(
        Closure $identity,
        string $wall,
    ): void {
        $floor = new CountingPreIdentityTimingFloor();

        try {
            (new UserChecker($floor))->checkPreAuth(new SecurityUser($identity()));
            $this->fail('The identity was admitted to a password check.');
        } catch (AccountStatusException $refusal) {
            $this->assertInstanceOf($wall, $refusal);
            // A custom-message status exception survives the authenticator's re-wrap and reaches the wire as
            // something other than the uniform 401, so a wall raising one would answer differently from a wrong
            // password and confirm the guess.
            $this->assertNotInstanceOf(CustomUserMessageAccountStatusException::class, $refusal);
        }

        $this->assertSame(1, $floor->invocations);
    }

    /**
     * @return iterable<string, array{Closure(): User, class-string<AccountStatusException>}>
     */
    public static function provideEveryNonActiveStatusIsWalledBeforeVerificationAndPaysTheFloorCases(): iterable
    {
        yield 'invited' => [static fn (): User => UserMother::invited(), InvitedAccountException::class];

        yield 'revoked' => [static fn (): User => UserMother::revoked(), RevokedAccountException::class];

        yield 'suspended' => [
            static function (): User {
                $user = UserMother::create();
                $user->suspend();

                return $user;
            },
            SuspendedAccountException::class,
        ];

        yield 'deactivated' => [
            static function (): User {
                $user = UserMother::create();
                $user->deactivate();

                return $user;
            },
            DeactivatedAccountException::class,
        ];
    }

    public function testAnActiveIdentityReachesItsPasswordCheckWithoutPayingTheFloor(): void
    {
        $floor = new CountingPreIdentityTimingFloor();

        (new UserChecker($floor))->checkPreAuth(new SecurityUser(UserMother::create()));

        $this->assertSame(0, $floor->invocations);
    }

    public function testALockedActiveIdentityIsLeftToTheLockListener(): void
    {
        // The lock is refused by `LockedIdentityLoginRefusalListener`, which `Security::login()` does not run.
        // Refusing it here would lock out the recovery-secret redemption, which signs in before it lifts the lock.
        $floor = new CountingPreIdentityTimingFloor();
        $user = UserMother::create();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt(SystemClock::now());
        }

        $this->assertTrue($user->isLockedAt(SystemClock::now()));

        (new UserChecker($floor))->checkPreAuth(new SecurityUser($user));

        $this->assertSame(0, $floor->invocations);
    }

    public function testPostAuthAdmitsWhateverReachedIt(): void
    {
        $this->expectNotToPerformAssertions();

        $suspended = UserMother::create();
        $suspended->suspend();

        (new UserChecker(new CountingPreIdentityTimingFloor()))->checkPostAuth(new SecurityUser($suspended));
    }

    public function testIgnoresAUserItDoesNotOwn(): void
    {
        $floor = new CountingPreIdentityTimingFloor();
        $checker = new UserChecker($floor);
        $foreign = $this->createStub(UserInterface::class);

        $checker->checkPreAuth($foreign);
        $checker->checkPostAuth($foreign);

        $this->assertSame(0, $floor->invocations);
    }
}
