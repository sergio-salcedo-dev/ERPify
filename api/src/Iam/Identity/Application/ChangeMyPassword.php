<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\NewPasswordMustDiffer;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Replaces the credential of a signed-in identity that re-proves the one it currently holds — the self-service
 * counterpart of {@see CompletePasswordReset}, which reaches the same mutation by consuming a single-use link.
 *
 * Every comparison against the credential is a closure the HTTP adapter builds, and each one receives the STORED
 * {@see HashedPassword} and answers with a boolean. That is the whole point of the shape: hashing and verifying
 * are algorithm knowledge, which belongs to Infrastructure, so neither the submitted current password nor the
 * submitted new one is ever a value this layer holds, logs or can leak into an exception context. The domain
 * only ever learns "matches" / "does not match", and receives the new credential already opaque.
 *
 * The ordering inside the transaction is the contract, and it is why both refusals are free of side effects:
 *   1. Load the aggregate under a pessimistic lock ({@see UserRepository::findByIdForUpdate()}). The lock
 *      serialises this write against the other credential writers on the same row (a reset completing, a
 *      concurrent change), so the "is it the same password?" decision reads committed state rather than a
 *      snapshot a rival transaction has already invalidated.
 *   2. Wall an identity that is no longer `ACTIVE`, in the vocabulary the rest of the product uses for it —
 *      403 `account-suspended` / `account-deactivated`, the same wall {@see CompletePasswordReset} raises from
 *      the same position. It sits ahead of every comparison so a walled identity pays no KDF at all, and it is
 *      what keeps the aggregate's own `InvalidIdentityTransition` a defensive floor rather than the answer a
 *      caller meets, which would speak of a lifecycle transition nobody asked for.
 *   3. Refuse a wrong current password ({@see InvalidCurrentPassword}) before anything mutates, through
 *      {@see ProveCurrentPassword} — the policy shared with {@see MintRecoverySecret} and
 *      {@see RevokeRecoverySecret}, the other two flows that re-prove a credential from a live
 *      session. An identity with no credential at all cannot re-prove one,
 *      so it takes the same refusal — a credential-less identity is `INVITED` and holds no session, so this
 *      arm is unreachable rather than an oracle.
 *   4. Refuse a new password equal to the stored one ({@see NewPasswordMustDiffer}). It has to happen here, not
 *      at the wire edge: deciding it needs the stored hash. Letting it through would emit a "password changed"
 *      fact, tear down every other device and mail a security notice for a change that did not happen.
 *   5. Only then hash the new password and mutate. The KDF runs under the row lock — a deliberate cost: the only
 *      contender for this row is the same identity's own credential writes, and hashing earlier would pay a KDF
 *      on every mistyped current password.
 *
 * Clearing the lockout is an explicit step, not an inherited one. A live lock is orthogonal to `ACTIVE`, so a
 * locked identity reaches here and leaves unlocked; that used to happen anyway, as a side effect of the
 * programmatic login two layers away firing `LoginSuccessEvent`. It is stated here because it is a decision:
 * re-proving the current secret and rotating it is strictly stronger evidence than the reset flow accepts for
 * the same relief ({@see CompletePasswordReset} clears it in the same position), and the counter the lock
 * summarises is about a credential that no longer exists.
 *
 * The two effects that must not be able to roll the change back run AFTER the commit, in an order that is itself
 * load-bearing. {@see RevokeSessionsBestEffort} first, because containment is the reason a credential change
 * revokes at all and a hung mail server must never hold the just-replaced credential's sessions open for the
 * length of an SMTP timeout; {@see SendPasswordChangedEmailBestEffort} second, because the send blocks and
 * running it inside the transaction would hold the user row lock across an SMTP round trip while announcing a
 * change a rollback could still undo. Both swallow their own failures: the credential change has committed, and
 * answering it with a 500 would tell the caller their password never changed.
 *
 * Revoking EVERY session (rather than every other one) is deliberate: it reuses the seam Identity is already
 * allowed to consume, and the caller's own device is signed back in by the HTTP adapter immediately afterwards.
 */
final readonly class ChangeMyPassword
{
    public function __construct(
        private UserRepository $users,
        private ProveCurrentPassword $proveCurrentPassword,
        private RevokeSessionsBestEffort $revokeSessions,
        private SendPasswordChangedEmailBestEffort $notifyPasswordChanged,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @param Closure(HashedPassword): bool $verifyCurrent   whether the submitted current password is the stored
     *                                                       credential
     * @param Closure(HashedPassword): bool $isSameAsCurrent whether the submitted NEW password is the stored
     *                                                       credential already
     * @param Closure(): HashedPassword     $hashNew         defers the KDF of the new password until both
     *                                                       refusals are past, so a rejected attempt never pays it
     *
     * @throws AccountSuspended       when the identity is SUSPENDED (403)
     * @throws AccountDeactivated     when the identity is DEACTIVATED or still INVITED (403)
     * @throws InvalidCurrentPassword when the submitted current password does not match the stored one (403)
     * @throws NewPasswordMustDiffer  when the submitted new password is the one already stored (422)
     * @throws UserNotFound           when the id resolves to no identity (404)
     */
    public function change(
        string $userId,
        Closure $verifyCurrent,
        Closure $isSameAsCurrent,
        Closure $hashNew,
    ): void {
        $email = $this->transactionManager->transactional(
            function () use ($userId, $verifyCurrent, $isSameAsCurrent, $hashNew): string {
                $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);
                $user->ensureActive();

                $this->proveCurrentPassword->ensure($user, $verifyCurrent);

                // Re-read after the proof rather than reusing a value it returned: this refusal needs the
                // stored credential too, and having the proof hand it back would make a collaborator whose
                // job is to decide also a supplier of the thing it decided about. The `??` cannot fire here
                // — `ensure()` has already refused a credential that is absent or unreadable.
                $current = $user->passwordHash() ?? throw new InvalidCurrentPassword();

                if ($isSameAsCurrent($current)) {
                    throw new NewPasswordMustDiffer();
                }

                $user->changePassword($hashNew());
                $user->clearLockout();

                $this->users->save($user);
                $this->eventBus->publish(...$user->pullDomainEvents());

                return $user->email();
            },
        );

        $this->revokeSessions->revoke($userId);
        $this->notifyPasswordChanged->send($email);
    }
}
