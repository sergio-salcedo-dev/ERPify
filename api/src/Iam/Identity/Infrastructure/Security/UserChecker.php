<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Override;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The admission step of the three-moment login (`credentials → identity → admission → session`): it decides,
 * between proving credentials and minting a session, whether an identity may be admitted — so "authenticated"
 * never implies "admitted".
 *
 * - {@see checkPreAuth} runs BEFORE the password is verified: an `INVITED` identity is rejected here (as a
 *   plain account-status failure) so its password is never checked and the failure collapses into the
 *   uniform pre-identity 401.
 * - {@see checkPostAuth} runs AFTER the password is verified (identity proven): a `SUSPENDED` / `DEACTIVATED`
 *   identity is rejected here, which aborts authentication so the firewall mints no session — the "no session
 *   artifacts" guarantee is structural, not something cleaned up afterwards.
 */
final class UserChecker implements UserCheckerInterface
{
    #[Override]
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof SecurityUser) {
            return;
        }

        if (IdentityStatus::INVITED === $user->status()) {
            throw new InvitedAccountException();
        }
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[Override]
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof SecurityUser) {
            return;
        }

        $status = $user->status();

        if (IdentityStatus::SUSPENDED === $status) {
            throw new SuspendedAccountException();
        }

        if (IdentityStatus::DEACTIVATED === $status) {
            throw new DeactivatedAccountException();
        }

        // A future time-boxed lockout state adds its post-auth arm here (a LockedException until an expiry).
    }
}
