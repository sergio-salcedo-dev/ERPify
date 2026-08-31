<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use LogicException;
use SensitiveParameter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Signs the calling device back in after its credential was replaced, having first re-read the aggregate and
 * confirmed the identity may still be admitted.
 *
 * `Security::login()` runs only half of the admission wall. It calls `checkPreAuth` explicitly, so the
 * `INVITED` wall fires; the other three — SUSPENDED, DEACTIVATED and the lockout — live in `checkPostAuth`,
 * which is driven by an authentication event that the programmatic path never dispatches. What does fire is
 * the session-minting listener, so without this the window between a credential change and its re-login can
 * mint an `ACTIVE` session for an identity an administrator has just suspended — and `SessionAdmissionGate`
 * cannot catch it, because it reads the session row and never the identity's status.
 *
 * `ensureActive()` restores **two** of those three arms. It matches on `IdentityStatus` alone, so a live
 * lockout is not re-applied here, and that is deliberate rather than an omission: every caller that can meet
 * one relieves it around this call (re-proving the current secret, or consuming a single-use link, is
 * stronger evidence than the lock summarises), and the ones that cannot consume a token already proving
 * control of the mailbox. Adding the lockout arm would refuse identities those flows just relieved — the
 * redemption most sharply, since it clears the lockout AFTER this call rather than before.
 *
 * The window is not uniform across callers, and the difference is worth naming. Three of them — change
 * password, complete reset, accept invitation — revoke sessions and then send a security notice on an
 * unrouted, blocking SMTP path before they get here, so the identity has been re-read many milliseconds
 * later than it was checked. The fourth, redeeming a recovery secret, reaches this method immediately after
 * an unlocked `ensureActive()`, with no teardown and no mail in between; what stands in for that width is
 * this method's own re-read plus the redemption's compensating session revoke, which undoes the login when
 * the locked pass refuses the consumption.
 *
 * This is deliberately blind to the flow that calls it. Four of them reach it, and the moment it learns
 * which is which, it stops being one policy in one place and becomes the four-way divergence it exists to
 * prevent.
 */
final readonly class ReauthenticateDevice
{
    private const string FIREWALL = 'main';

    public function __construct(
        private UserRepository $users,
        private Security $security,
    ) {
    }

    public function reauthenticate(#[SensitiveParameter] string $emailIdentifier): void
    {
        $user = $this->users->findByEmail(Email::from($emailIdentifier))
            // Reached only if the row vanished between a committed credential change and this line, which is
            // an orchestration fault rather than a user path: fail closed instead of minting a session for an
            // identity nobody can vouch for.
            ?? throw new LogicException('The identity whose credential was just replaced no longer exists.');

        $user->ensureActive();

        // The security adapter is built from the aggregate already in hand rather than re-resolved through
        // the user provider: that provider's whole body is this same lookup followed by this same wrapping,
        // so routing through it would run the identity query twice per re-login and give the firewall a
        // second, unlocked read of a row this method has already decided about.
        $this->security->login(new SecurityUser($user), firewallName: self::FIREWALL);
    }
}
