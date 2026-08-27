<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Exception\SelfUnlockForbidden;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Clears a persisted lockout on another identity — the administrative recovery lever #602 documents as
 * missing: a per-identity lockout ({@see \Erpify\Iam\Identity\Domain\Entity\User::recordFailedAttempt()}, ten
 * attempts, fifteen minutes) recovers only through a successful login or a completed password reset, both of
 * which an attacker who merely knows the target's email can hold shut indefinitely. This is the third
 * recovery edge, reachable only behind `users.unlock` rather than the target's own credential.
 *
 * Wraps {@see \Erpify\Iam\Identity\Domain\Entity\User::clearLockout()}, which already does the right thing —
 * idempotent, and already reporting whether it mutated anything. That return value is surfaced rather than
 * discarded: a caller invoking this against an identity that was never locked gets an honest "nothing to
 * clear" in the response, never a fabricated claim of recovery.
 *
 * The audit row is written whether or not the call mutated anything, and that is a deliberate departure from
 * {@see ChangeUserRoles}, which skips its compliance row on a redundant no-op. There the row records WHAT
 * changed, and a no-op changed nothing worth restating. Here the row records that the lever was INVOKED — by
 * whom, against whom, and whether the target was actually locked at the time — and that fact is unchanged by
 * the outcome: an administrator reaching for `users.unlock` against an account that turns out not to be locked
 * is exactly the kind of use this security-level trail exists to make reviewable (a colleague being probed for
 * lockout status is itself a signal worth keeping, not only a successful recovery).
 *
 * **An administrator may never unlock their own identity**, and the guard mirrors
 * {@see FulfilIdentityErasure::refuseSelfErasure()} deliberately: read the trusted actor from
 * {@see ActorContextFactory} — never the request body, which the caller controls — compare case-insensitively
 * (RFC 4122 hex), and refuse before the transaction opens, so a self-targeted call touches no row and writes
 * no audit entry. The reason is not "a locked-out actor cannot make this request" (a locked-out session was
 * never admitted past the firewall in the first place, so that scenario cannot arise); it is that granting
 * `users.unlock` over one's own identity would make it a second, credential-independent path into an account —
 * exactly the authentication bypass the lockout exists to prevent, and precisely the failure mode #602's design
 * review named as non-negotiable.
 */
final readonly class UnlockUserAccount
{
    private const string UNLOCKED_ACTION = 'ACCOUNT_UNLOCKED_BY_ADMIN';

    public function __construct(
        private UserRepository $users,
        private AuditLogger $auditLogger,
        private ActorContextFactory $actorContext,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws SelfUnlockForbidden when the acting administrator targets their own identity (409)
     * @throws UserNotFound        when the id resolves to no identity (404)
     */
    public function run(string $userId): UnlockUserAccountResult
    {
        Uuid::ensure($userId);
        $this->refuseSelfUnlock($userId);

        return $this->transactionManager->transactional(function () use ($userId): UnlockUserAccountResult {
            $user = $this->users->findByIdForUpdate($userId) ?? throw UserNotFound::withId($userId);

            $unlocked = $user->clearLockout();

            if ($unlocked) {
                $this->users->save($user);
            }

            $this->auditUnlock($userId, $unlocked);

            return new UnlockUserAccountResult($user, $unlocked);
        });
    }

    private function refuseSelfUnlock(string $userId): void
    {
        $actorId = $this->actorContext->current()->actorId;

        // RFC 4122 hex is case-insensitive: the route id and the sealed actor id can spell one UUID in
        // different case, so compare case-insensitively (as the self-erasure guard does) — a `===` here
        // would be bypassable by an admin who merely re-cased their own id in the request path.
        if (null !== $actorId && 0 === \strcasecmp($actorId, $userId)) {
            throw SelfUnlockForbidden::forActor($userId);
        }
    }

    private function auditUnlock(string $userId, bool $unlocked): void
    {
        $this->auditLogger->log(
            self::UNLOCKED_ACTION,
            AuditLevel::SECURITY,
            AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId),
            ['unlocked' => $unlocked],
        );
    }
}
