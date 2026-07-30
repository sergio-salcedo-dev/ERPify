<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Entity\User;
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
 * Completes a password reset from a selector-verifier link `<id>.<secret>` and a deferred supplier of the new
 * hashed credential. Hashing stays in Infrastructure (the HTTP adapter builds the closure, exactly as
 * {@see CreateUser} receives an already-opaque credential) but is only INVOKED once the token has resolved
 * live: a dead link must never cost a KDF run, or the unauthenticated endpoint becomes an argon2id
 * amplification vector — anyone posting garbage tokens would burn tens of ms of CPU per request. The dead
 * cases stay mutually uniform (they all do the same cheap resolve work); only liveness changes the cost, and
 * the response reveals liveness anyway.
 *
 * The heart of the flow, and the ordering is load-bearing:
 *   1. Resolve the token opaquely — a malformed, unknown, expired or already-consumed link all raise the SAME
 *      {@see InvalidResetToken} BEFORE anything mutates, so the three death cases are byte-identical (SI-13).
 *   2. Ask the identity to admit itself ({@see User::ensureActive()}), WITHOUT consuming the token or
 *      mutating. The aggregate names the reason its own status implies; a valid token proves email control,
 *      so that specificity is safe here. The token stays live for a later attempt if the account is
 *      reactivated within its TTL.
 *   3. In ONE transaction: CONSUME the token (a conditional delete whose affected-row count is the single-use
 *      guard — two concurrent completions serialise on the row lock, only the first deletes a row, the loser
 *      aborts with {@see InvalidResetToken}), THEN fix the new credential and clear any lockout. Consuming first
 *      means a lost race mutates nothing.
 *   4. AFTER that commit, revoke every session (including the current one — whoever resets holds no trusted
 *      session) through {@see RevokeSessionsBestEffort}: the credential change already de-authenticates the old
 *      sessions natively, so a revoke failure is swallowed there rather than stranding a reset that committed.
 *   5. LAST, notify the identity that its credential changed, through {@see SendPasswordChangedEmailBestEffort}.
 *      Both halves of that position are load-bearing. Post-commit, because the send blocks (Symfony's
 *      `SendEmailMessage` is deliberately unrouted) and running it inside the transaction would hold the user
 *      row lock across an SMTP round trip and announce a change a rollback could still undo. And after the
 *      revoke, because revocation is the containment this endpoint exists to perform: a hung mail server must
 *      never keep the sessions of a just-compromised account alive for the length of an SMTP timeout.
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
        private SendPasswordChangedEmailBestEffort $notifyPasswordChanged,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @param Closure(): HashedPassword $hashNewPassword defers the KDF of the submitted password; invoked only
     *                                                   after the token resolves live and the identity passes
     *                                                   the status wall, so dead links stay KDF-free
     *
     * @throws InvalidResetToken  when the token is malformed, unknown, expired or already consumed (uniform)
     * @throws AccountSuspended   when the identity is suspended between request and complete (403)
     * @throws AccountDeactivated when the identity is deactivated between request and complete (403)
     *
     * @return string the identity's canonical email, so the HTTP adapter can establish the post-reset session
     *                (programmatic login) without handling the identity aggregate across the boundary
     */
    public function complete(#[SensitiveParameter] string $token, Closure $hashNewPassword): string
    {
        $resetToken = $this->resolve($token);
        $user = $this->users->findById($resetToken->userId()) ?? throw new InvalidResetToken();

        // Cheap first sampling of the wall: the common already-walled case is rejected without paying the
        // KDF or opening a transaction. The authoritative sampling is repeated under the row lock below.
        $user->ensureActive();

        // The KDF runs outside the transaction (no row lock held for tens of ms); the rare token consumed
        // between here and the conditional delete below is caught by the consume() affected-rows guard.
        $newPassword = $hashNewPassword();

        $email = $this->transactionManager->transactional(function () use ($newPassword, $resetToken): string {
            // Re-sample the status from the LOCKED row: an admin suspension/deactivation committed between
            // the load above and this transaction must wall the reset — without the lock the check races the
            // admin write and a walled identity could still complete. The lock also serialises against the
            // forgot supersede, and the re-read is the fresh row, never the identity map's snapshot.
            $user = $this->users->findByIdForUpdate($resetToken->userId()) ?? throw new InvalidResetToken();
            $user->ensureActive();

            if (!$this->tokens->consume($resetToken)) {
                throw new InvalidResetToken();
            }

            $user->resetPassword($newPassword);
            $user->clearLockout();

            $this->users->save($user);
            $this->eventBus->publish(...$user->pullDomainEvents());

            return $user->email();
        });

        $this->revokeSessions->revoke($resetToken->userId());
        $this->notifyPasswordChanged->send($email);

        return $email;
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
