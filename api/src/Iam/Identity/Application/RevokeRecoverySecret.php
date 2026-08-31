<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Destroys the caller's own recovery secret, against a re-proof of the password they currently hold. It is the
 * explicit, visible eviction this design owes its users in exchange for never destroying a secret silently: a
 * password change leaves a live secret standing, so revocation is the only way a holder stops being one short
 * of redeeming it or waiting out the decade.
 *
 * **The re-proof is what keeps a stolen session from closing the door this channel exists to open.** Destroying
 * the credential is permanent and its owner cannot undo it from the position this channel exists to rescue
 * them from: the plaintext lived for exactly one response, and minting a replacement demands the very password
 * a locked-out administrator has no session to offer it through. So an attacker holding a live session could
 * otherwise spend one request to retire the recovery secret, read the owner's address, and hold the account
 * shut on the email-keyed lockout with no way back in. Requiring the credential makes that act cost exactly
 * what minting and replacing the password cost, which is the only consistent price for the three writes that
 * can reach it.
 *
 * The credential comparison arrives as a closure the HTTP adapter builds and is decided by
 * {@see ProveCurrentPassword}, the collaborator this shares with {@see ChangeMyPassword} and
 * {@see MintRecoverySecret}: hashing and verifying are algorithm knowledge belonging to Infrastructure, so the
 * submitted password is never a value this layer holds, logs or can leak into an exception context.
 *
 * The ordering inside the transaction is the contract:
 *   1. Load the identity under a pessimistic lock. Proving the credential means reading the user row, so this
 *      flow holds TWO locks, and `identity_user` before `identity_recovery_secret` is the order minting and
 *      redemption take as well. A deadlock cycle needs two transactions acquiring the same pair in opposite
 *      orders; no path over these tables acquires the secret first, so there is no order to be opposite to.
 *   2. Refuse a wrong current password BEFORE the existence of a secret is consulted. The order is the
 *      security property, not a preference: answering differently to someone who has not re-proved the
 *      credential would turn a stolen session into an oracle over whether a recovery secret exists.
 *   3. Read the row `FOR UPDATE` and only then remove it, rather than deleting by predicate. That costs one
 *      extra round trip and buys the guarantee the concurrency matrix names: a redemption in flight holds this
 *      same row, so the two serialise on it and the loser is a plain no-op — never a revocation landing
 *      between a redemption's verify and its consume, which is the one interleaving that could retire a row
 *      the redemption had already decided about.
 *
 * A non-`ACTIVE` identity is NOT walled here, unlike minting. Walling would preserve the secret of a suspended
 * identity who cannot redeem it anyway, which protects nothing and takes away the one act that reliably ends a
 * credential its owner believes is compromised.
 *
 * Idempotent by construction — an identity with no secret is a successful, empty revocation, and the caller
 * has re-proved their credential by the time that answer is reached. Whether a row was actually removed is
 * reported so the audit projection describes what happened rather than what was asked for: a revocation of
 * nothing is not a revocation, and recording one would put a security row in the trail for an act that never
 * took place.
 */
final readonly class RevokeRecoverySecret
{
    public function __construct(
        private UserRepository $users,
        private RecoverySecretRepository $secrets,
        private ProveCurrentPassword $proveCurrentPassword,
        private RecordRecoverySecretAuditBestEffort $audit,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @param Closure(HashedPassword): bool $verifyCurrent whether the submitted current password is the
     *                                                     stored credential
     *
     * @throws InvalidCurrentPassword when the submitted current password does not match the stored one (403)
     * @throws UserNotFound           when the id resolves to no identity (404)
     */
    public function revoke(string $userId, Closure $verifyCurrent): void
    {
        $revoked = $this->transactionManager->transactional(function () use ($userId, $verifyCurrent): bool {
            $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);

            $this->proveCurrentPassword->ensure($user, $verifyCurrent);

            $secret = $this->secrets->findByUserIdForUpdate($userId);

            if (!$secret instanceof RecoverySecret) {
                return false;
            }

            $secret->revoke();

            // Inside the transaction, for the reason redemption publishes there: the durable record of the
            // revocation lands with the delete or not at all.
            $this->eventBus->publish(...$secret->pullDomainEvents());
            $this->secrets->remove($secret);

            return true;
        });

        // Post-commit and best-effort, for the same reason the other two projections are: the secret is
        // already destroyed and unrecoverable, so a failing audit write may not answer the caller with a 500
        // that says their revocation did not happen.
        if ($revoked) {
            $this->audit->recordRevoked($userId);
        }
    }
}
