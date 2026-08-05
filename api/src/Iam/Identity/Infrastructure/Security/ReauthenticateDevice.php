<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use LogicException;
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
 * lockout is not re-applied here, and that is deliberate rather than an omission: the two callers that could
 * meet one clear it inside their own transaction (re-proving the current secret, or consuming a single-use
 * link, is stronger evidence than the lock summarises), and the third consumes a token that already proves
 * control of the mailbox. Adding the lockout arm here would refuse identities those flows just relieved.
 *
 * The window is not microseconds wide: all three callers revoke sessions and then send a security notice on
 * an unrouted, blocking SMTP path before they get here.
 *
 * This is deliberately blind to the flow that calls it. Three of them do — change password, complete reset,
 * accept invitation — and the moment it learns which is which, it stops being one policy in one place and
 * becomes the three-way divergence it exists to prevent.
 */
final readonly class ReauthenticateDevice
{
    private const string FIREWALL = 'main';

    public function __construct(
        private UserRepository $users,
        private UserProvider $userProvider,
        private Security $security,
    ) {
    }

    public function reauthenticate(string $emailIdentifier): void
    {
        $user = $this->users->findByEmail(Email::from($emailIdentifier))
            // Reached only if the row vanished between a committed credential change and this line, which is
            // an orchestration fault rather than a user path: fail closed instead of minting a session for an
            // identity nobody can vouch for.
            ?? throw new LogicException('The identity whose credential was just replaced no longer exists.');

        $user->ensureActive();

        $this->security->login(
            $this->userProvider->loadUserByIdentifier($emailIdentifier),
            firewallName: self::FIREWALL,
        );
    }
}
