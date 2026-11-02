<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Infrastructure\Security\LockedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\LockedIdentityLoginRefusalListener;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\CountingPreIdentityTimingFloor;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\EventListener\CheckCredentialsListener;
use Symfony\Component\Security\Http\EventListener\UserCheckerListener;

/**
 * A lockout in force is refused before the password is verified — never after, where the correct password would
 * be the one answer that differs — and only on a password login, so the device re-authentications that carry no
 * password (the recovery-secret redemption among them, which signs in before it lifts the lock) pass untouched.
 *
 * @internal
 */
#[CoversClass(LockedIdentityLoginRefusalListener::class)]
final class LockedIdentityLoginRefusalListenerTest extends TestCase
{
    private const string NOW = '2026-07-11T12:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
    }

    public function testRefusesAPasswordLoginForALockedIdentityWithOneFloor(): void
    {
        $floor = new CountingPreIdentityTimingFloor();
        $caught = null;

        try {
            $this->listener($floor)($this->passwordEvent($this->lockedUser()));
        } catch (LockedAccountException $lockedAccountException) {
            $caught = $lockedAccountException;
        }

        $this->assertInstanceOf(LockedAccountException::class, $caught);
        // A custom-message status exception would survive the authenticator's re-wrap as something other than
        // the uniform 401.
        $this->assertNotInstanceOf(CustomUserMessageAccountStatusException::class, $caught);
        $this->assertSame(1, $floor->invocations);
    }

    public function testLeavesAnUnlockedIdentityToItsPasswordCheck(): void
    {
        $floor = new CountingPreIdentityTimingFloor();

        $this->listener($floor)($this->passwordEvent(UserMother::create()));

        $this->assertSame(0, $floor->invocations);
    }

    public function testLeavesAnIdentityWhoseLockEndsAtThisInstantToItsPasswordCheck(): void
    {
        // The window is half-open: at the instant `lockedUntil` names, the lock no longer stands, which is also
        // the instant `User::recordFailedAttempt()` starts counting afresh.
        $floor = new CountingPreIdentityTimingFloor();
        $user = $this->lockedUser();

        $expiry = '2026-07-11T12:15:00+00:00';
        SystemClock::set(FixedClock::at($expiry));
        $this->listener($floor, $expiry)($this->passwordEvent($user));

        $this->assertSame(0, $floor->invocations);
    }

    public function testRefusesALockedIdentityUpToTheLastInstantOfItsWindow(): void
    {
        $this->expectException(LockedAccountException::class);

        $user = $this->lockedUser();

        $lastInstant = '2026-07-11T12:14:59+00:00';
        SystemClock::set(FixedClock::at($lastInstant));
        $this->listener(new CountingPreIdentityTimingFloor(), $lastInstant)($this->passwordEvent($user));
    }

    public function testLeavesAPasswordAlreadyVerifiedToItsCaller(): void
    {
        $floor = new CountingPreIdentityTimingFloor();
        $event = $this->passwordEvent($this->lockedUser());
        $credentials = $event->getPassport()->getBadge(PasswordCredentials::class);
        $this->assertInstanceOf(PasswordCredentials::class, $credentials);
        $credentials->markResolved();

        $this->listener($floor)($event);

        $this->assertSame(0, $floor->invocations);
    }

    public function testIgnoresAUserItDoesNotOwn(): void
    {
        $floor = new CountingPreIdentityTimingFloor();
        $passport = new Passport(
            new UserBadge('alice@erpify.test', static fn (): InMemoryUser => new InMemoryUser('alice', null)),
            new PasswordCredentials('a-guess'),
        );

        $this->listener($floor)(new CheckPassportEvent($this->createStub(AuthenticatorInterface::class), $passport));

        $this->assertSame(0, $floor->invocations);
    }

    public function testLeavesALoginWithoutAPasswordToItsCaller(): void
    {
        // A passport that verifies no password guesses nothing, so the lock has nothing to protect there. The
        // programmatic re-logins never reach the listener at all (`Security::login()` dispatches no passport
        // event); this pins the scope for an authenticator that would.
        $floor = new CountingPreIdentityTimingFloor();
        $user = $this->lockedUser();
        $passport = new SelfValidatingPassport(
            new UserBadge('alice@erpify.test', static fn (): SecurityUser => new SecurityUser($user)),
        );

        $this->listener($floor)(
            new CheckPassportEvent($this->createStub(AuthenticatorInterface::class), $passport),
        );

        $this->assertSame(0, $floor->invocations);
    }

    public function testRunsAfterTheIdentityIsResolvedAndBeforeThePasswordIsVerified(): void
    {
        $resolved = $this->priorityOf(UserCheckerListener::getSubscribedEvents());
        $verified = $this->priorityOf(CheckCredentialsListener::getSubscribedEvents());

        $this->assertLessThan($resolved, LockedIdentityLoginRefusalListener::PRIORITY);
        $this->assertGreaterThan($verified, LockedIdentityLoginRefusalListener::PRIORITY);
    }

    /**
     * @param array<string, mixed> $subscribed
     */
    private function priorityOf(array $subscribed): int
    {
        $entry = $subscribed[CheckPassportEvent::class] ?? null;

        if (\is_string($entry)) {
            return 0;
        }

        $this->assertIsArray($entry);
        $priority = $entry[1] ?? 0;
        $this->assertIsInt($priority);

        return $priority;
    }

    private function listener(
        CountingPreIdentityTimingFloor $floor,
        string $instant = self::NOW,
    ): LockedIdentityLoginRefusalListener {
        return new LockedIdentityLoginRefusalListener(FixedClock::at($instant), $floor);
    }

    private function passwordEvent(User $user): CheckPassportEvent
    {
        $passport = new Passport(
            new UserBadge('alice@erpify.test', static fn (): SecurityUser => new SecurityUser($user)),
            new PasswordCredentials('a-guess'),
        );

        return new CheckPassportEvent($this->createStub(AuthenticatorInterface::class), $passport);
    }

    private function lockedUser(): User
    {
        $user = UserMother::create();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt(SystemClock::now());
        }

        return $user;
    }
}
