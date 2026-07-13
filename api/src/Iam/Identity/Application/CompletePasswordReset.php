<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidResetToken;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use SensitiveParameter;

/**
 * Completes a password reset from a selector-verifier link `<id>.<secret>` and an already-hashed new
 * credential (hashing stays in Infrastructure, exactly as {@see CreateUser} receives its credential).
 *
 * The heart of the flow, and the ordering is load-bearing:
 *   1. Resolve the token opaquely — a malformed, unknown, expired or already-consumed link all raise the SAME
 *      {@see InvalidResetToken} BEFORE anything mutates, so the three death cases are byte-identical (SI-13).
 *   2. Wall a non-`ACTIVE` identity (suspended/deactivated between request and complete) with the post-identity
 *      wall, WITHOUT consuming the token or mutating (a valid token proves email control → graduated
 *      specificity, SI-14; the token stays live for a later attempt if the account is reactivated).
 *   3. In ONE transaction: CONSUME the token (a conditional delete whose affected-row count is the single-use
 *      guard — two concurrent completions serialise on the row lock, only the first deletes a row, the loser
 *      aborts with {@see InvalidResetToken}), THEN fix the new credential and clear any lockout. Consuming first
 *      means a lost race mutates nothing.
 *   4. AFTER that commit, revoke every session (including the current one — whoever resets holds no trusted
 *      session) through {@see RevokeSessionsBestEffort}: the credential change already de-authenticates the old
 *      sessions natively, so a revoke failure is swallowed there rather than stranding a reset that committed.
 *
 * It returns the identity's email so the HTTP adapter can establish the session (programmatic login on the
 * just-set credential, reusing the native id regeneration): a successful reset signs the user in, and every
 * prior session was already revoked above — reset everywhere, then sign in here.
 */
final readonly class CompletePasswordReset
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetTokenRepository $tokens,
        private RevokeSessionsBestEffort $revokeSessions,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidResetToken  when the token is malformed, unknown, expired or already consumed (uniform)
     * @throws AccountSuspended   when the identity is suspended between request and complete (403)
     * @throws AccountDeactivated when the identity is deactivated between request and complete (403)
     *
     * @return string the identity's canonical email, so the HTTP adapter can establish the post-reset session
     *                (programmatic login) without handling the identity aggregate across the boundary
     */
    public function complete(#[SensitiveParameter] string $token, HashedPassword $newPassword): string
    {
        $resetToken = $this->resolve($token);
        $user = $this->users->findById($resetToken->userId()) ?? throw new InvalidResetToken();

        if (!$user->isActive()) {
            throw IdentityStatus::SUSPENDED === $user->status() ? new AccountSuspended() : new AccountDeactivated();
        }

        $this->transactionManager->transactional(function () use ($user, $newPassword, $resetToken): void {
            if (!$this->tokens->consume($resetToken)) {
                throw new InvalidResetToken();
            }

            $user->resetPassword($newPassword);
            $user->clearLockout();

            $this->users->save($user);
            $this->eventBus->publish(...$user->pullDomainEvents());
        });

        $this->revokeSessions->revoke($resetToken->userId());

        return $user->email();
    }

    /**
     * Splits `<id>.<secret>`, selects the row by id and verifies the secret constant-time. Every failure —
     * bad shape, missing row, wrong or lapsed secret — collapses to the SAME {@see InvalidResetToken}. A
     * non-UUID selector resolves to a missing row (the repository treats a malformed id as absent, never an
     * `InvalidUuidException`), so the wire type stays uniform and knowing an id buys nothing without the secret.
     */
    private function resolve(#[SensitiveParameter] string $token): PasswordResetToken
    {
        $parts = \explode('.', $token, 2);

        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new InvalidResetToken();
        }

        $resetToken = $this->tokens->findById($parts[0]) ?? throw new InvalidResetToken();

        if (!$resetToken->verify($parts[1], $this->clock->now())) {
            throw new InvalidResetToken();
        }

        return $resetToken;
    }
}
