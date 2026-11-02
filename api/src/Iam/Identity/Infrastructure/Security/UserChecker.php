<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Override;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The admission step of the login (`credentials → identity → admission → session`): it decides whether an
 * identity may be admitted, so "authenticated" never implies "admitted".
 *
 * Every refusal happens in {@see checkPreAuth}, BEFORE the password is verified, and collapses into the uniform
 * pre-identity 401. A password is only ever checked for an `ACTIVE` identity: verifying one for a refused
 * identity would turn the refusal into an oracle, because the correct password would be the one answer that
 * differs and a non-`ACTIVE` identity counts no failed attempt. The lockout, orthogonal to the status, is refused
 * at the same point by {@see LockedIdentityLoginRefusalListener}, which `Security::login()` does not run.
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public function __construct(private PreIdentityTimingFloor $timingFloor)
    {
    }

    #[Override]
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof SecurityUser) {
            return;
        }

        // Enumerated rather than defaulted, so a state added later fails the build instead of falling through
        // into admission.
        $wall = match ($user->status()) {
            IdentityStatus::INVITED => new InvitedAccountException(),
            IdentityStatus::REVOKED => new RevokedAccountException(),
            IdentityStatus::SUSPENDED => new SuspendedAccountException(),
            IdentityStatus::DEACTIVATED => new DeactivatedAccountException(),
            IdentityStatus::ACTIVE => null,
        };

        if (null !== $wall) {
            // why: this branch skips the password verification a wrong-password failure pays, so it would
            // otherwise answer measurably faster — a state oracle. Pay the same hashing work the equalised
            // not-found path pays.
            $this->timingFloor->equalise();

            throw $wall;
        }
    }

    /**
     * Admits whatever reached it: every wall runs before verification, in {@see checkPreAuth} and in
     * {@see LockedIdentityLoginRefusalListener}.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[Override]
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
