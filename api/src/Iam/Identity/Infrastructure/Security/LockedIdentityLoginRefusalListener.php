<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Erpify\Shared\Clock\Domain\Clock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Refuses a password login for an identity whose lockout is in force BEFORE the password is verified, paying the
 * timing floor, so the refusal is the uniform pre-identity 401 and never confirms a guess.
 *
 * Verifying first would turn the lock into an oracle: a locked identity counts no failure while the lock stands,
 * so the guessing goes on unbounded and the correct password is the one answer that differs.
 *
 * It runs after `UserCheckerListener` (256), which resolves the identity and refuses the non-`ACTIVE` statuses,
 * and before `CheckCredentialsListener` (0), which verifies the password. The device re-authentications that
 * follow a reset, a change of password and a recovery-secret redemption log in through `Security::login()`,
 * which dispatches no passport event, so they never reach this listener — and the redemption signs in BEFORE it
 * clears the lock it exists to lift, which is why the lock is not refused in `checkPreAuth`, which
 * `Security::login()` does run. It acts only on a passport carrying unresolved {@see PasswordCredentials}, so an
 * authenticator added later that proves identity by other means is left to decide the lock for itself.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: self::PRIORITY)]
final readonly class LockedIdentityLoginRefusalListener
{
    public const int PRIORITY = 128;

    public function __construct(
        private Clock $clock,
        private PreIdentityTimingFloor $timingFloor,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        if (!$passport->hasBadge(PasswordCredentials::class)) {
            return;
        }

        $credentials = $passport->getBadge(PasswordCredentials::class);

        if (!$credentials instanceof PasswordCredentials || $credentials->isResolved()) {
            return;
        }

        $user = $passport->getUser();

        if (!$user instanceof SecurityUser || !$user->isLockedAt($this->clock->now())) {
            return;
        }

        $this->timingFloor->equalise();

        throw new LockedAccountException();
    }
}
