<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Shared\Clock\Domain\Clock;
use Override;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The admission step of the three-moment login (`credentials → identity → admission → session`): it decides,
 * between proving credentials and minting a session, whether an identity may be admitted — so "authenticated"
 * never implies "admitted".
 *
 * - {@see checkPreAuth} runs BEFORE the password is verified: the credential-less states — `INVITED` and the
 *   terminal `REVOKED` — are rejected here (as plain account-status failures) so no password is ever checked
 *   and both failures collapse into the same uniform pre-identity 401.
 * - {@see checkPostAuth} runs AFTER the password is verified (identity proven): a `SUSPENDED` / `DEACTIVATED`
 *   identity is rejected here, which aborts authentication so the firewall mints no session — the "no session
 *   artifacts" guarantee is structural, not something cleaned up afterwards.
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private Clock $clock,
        private PreIdentityTimingFloor $timingFloor,
    ) {
    }

    #[Override]
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof SecurityUser) {
            return;
        }

        // Both credential-less states are walled here, and enumerated rather than defaulted so a state added
        // later fails the build instead of falling through into admission.
        $credentialLessWall = match ($user->status()) {
            IdentityStatus::INVITED => new InvitedAccountException(),
            IdentityStatus::REVOKED => new RevokedAccountException(),
            IdentityStatus::ACTIVE, IdentityStatus::SUSPENDED, IdentityStatus::DEACTIVATED => null,
        };

        if (null !== $credentialLessWall) {
            // why: neither identity holds a credential, so this branch is reached before any password
            // verification and would otherwise answer measurably faster than a wrong-password failure — a
            // pre-identity state oracle. Pay the same hashing work the equalised not-found path pays.
            $this->timingFloor->equalise();

            throw $credentialLessWall;
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

        // The lock arm runs LAST: a lifecycle wall (SUSPENDED/DEACTIVATED) precedes the transient lockout, so a
        // suspended identity that also tripped the counter sees the suspended wall, never `locked`
        // (`SUSPENDED + locked` is not representable). The lock is orthogonal to status: an `ACTIVE` identity
        // reaches here and is walled only while its lockout is still in force.
        if ($user->isLockedAt($this->clock->now())) {
            throw new LockedAccountException();
        }
    }
}
