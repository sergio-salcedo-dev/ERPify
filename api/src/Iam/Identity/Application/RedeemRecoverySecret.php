<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidRecoverySecret;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use SensitiveParameter;

/**
 * Redeems a `<selector>.<secret>` presented by an anonymous caller: it establishes an authenticated session
 * for the identity the secret belongs to, clears any lockout, and retires the row.
 *
 * The session it mints survives every later re-locking, and that is the whole mechanism rather than a side
 * effect: the admission gate reads the session row and never `locked_until`, so an attacker who can hold the
 * email-keyed lock closed for ever cannot evict the owner once they are back in.
 *
 * **The re-login happens BEFORE the row is consumed, and inverting that order is the defect this class exists
 * to avoid.** The reset flow re-authenticates post-commit through a helper that swallows every `Throwable` and
 * answers 204 regardless, which is right there — the credential was already replaced. Here the same shape
 * would be the worst possible outcome: secret spent, row gone, a cheerful 204, and a sole administrator still
 * locked out with nothing left to present. With the order this way round, a failure to establish the session
 * costs nothing at all.
 *
 * `Security::login()` reaches this layer as a closure the adapter supplies, so the framework seam stays in
 * Infrastructure while the ORDER stays here — the order is the security property, and spreading it across a
 * controller is how a later edit reorders it without anyone noticing.
 *
 * The flow, and every step of it is load-bearing:
 *   1. Resolve the selector WITHOUT a lock and verify the secret against the row it names. This sampling
 *      decides nothing persisted; it exists so a dead presentation never reaches the session machinery. Every
 *      death case — malformed, unknown selector, wrong secret, lapsed secret — raises the SAME
 *      {@see InvalidRecoverySecret}, and the per-selector budget the adapter spends folds into it too.
 *   2. Ask the identity to admit itself, WITHOUT consuming anything. A valid secret over a walled identity is
 *      the one IDENTIFIED refusal on this endpoint (403), and the row stays live for a later attempt.
 *   3. Establish the session. A failure here — the store is unreachable, the identity was walled in the
 *      meantime — leaves the secret entirely intact.
 *   4. THEN, in one transaction: take the user row `FOR UPDATE`, re-sample the status from it, re-read the
 *      secret `FOR UPDATE` and re-verify against THAT row, clear the lockout and retire the secret. Taking
 *      the user first is the invariant shared with minting and with the failed-login path, so no acquisition
 *      anywhere reaches the secret ahead of the user. Re-verifying under the lock is what makes "at most one
 *      consumption" a property of the system rather than of a comment: without it two concurrent redemptions
 *      both verify a row neither has yet removed.
 *   5. If step 4's status wall fires, REVOKE the session step 3 established. It is already committed — the
 *      login's own listeners insert and commit the `iam_session` row — and the admission gate reads that row
 *      and never the identity's status, so a 403 body over a live session would leave an administrator's
 *      suspension defeated by a race an attacker can simply retry. The two writes live in different
 *      transactions and no ordering makes them one, so compensating is the only close available.
 *
 * **"Single use" here means at most one PERSISTED CONSUMPTION, not at most one authentication.** Steps 3 and 4
 * are not one atomic act — `RecoverySecret`, `User.lockout` and the session are three state machines and no
 * transaction spans them — so a redemption that loses the race at step 4 has already produced a session and
 * then meets the opaque refusal. That is accepted, and it is the safe direction: the alternative orders the
 * consumption first and strands the owner whenever the session mint fails.
 *
 * If step 4 fails after step 3 succeeded, the session is NOT rolled back — the owner has recovered access,
 * which is the objective — but the endpoint does not answer 204, and the secret stays live and re-redeemable.
 * The partial state is RETRYABLE rather than idempotent: a second redemption completes the persisted cleanup
 * without anyone having to mint a new secret.
 *
 * Redemption deliberately does NOT rotate the secret into the response. A lost response would otherwise spend
 * the only secret of a customer with no shell, and it would turn a one-off theft into permanent undetectable
 * access — destroying the one detection property this design has, which is that the owner sees their secret
 * disappear. The fixed point is: recover, then mint again explicitly.
 *
 * Its object coupling sits one above the default threshold. More than half of that number is the failure
 * vocabulary this flow declares — the opaque refusal and the two status walls — which is a published
 * contract rather than a hidden dependency; the rest is one collaborator per step of a single atomic act:
 * two repositories, the audit projection, the compensating session revoke, the event bus, the transaction
 * seam and the clock. The compensation is what pushed it over, and collapsing any of them to satisfy the
 * metric would hide a step this flow's correctness is stated in terms of.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class RedeemRecoverySecret
{
    public function __construct(
        private UserRepository $users,
        private RecoverySecretRepository $secrets,
        private RecordRecoverySecretAuditBestEffort $audit,
        private RevokeSessionsBestEffort $revokeSessions,
        private EventBus $eventBus,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @param Closure(string): void $establishSession receives the identity's canonical email and signs the
     *                                                device in; anything it raises leaves the secret untouched
     *
     * @throws InvalidRecoverySecret when the presentation is malformed, unknown, lapsed, wrong or already
     *                               consumed (uniform)
     * @throws AccountSuspended      when the identity is SUSPENDED (403); the row is not consumed
     * @throws AccountDeactivated    when the identity is DEACTIVATED, INVITED or REVOKED (403); likewise
     */
    public function redeem(#[SensitiveParameter] string $presented, Closure $establishSession): void
    {
        $now = $this->clock->now();
        [$selector, $secret] = $this->split($presented);

        // Unlocked, and it authorizes nothing: all this pass may conclude is which identity to lock next.
        $resolved = $this->secrets->findBySelector($selector) ?? throw new InvalidRecoverySecret();

        if (!$resolved->verify($secret, $now)) {
            throw new InvalidRecoverySecret();
        }

        $userId = $resolved->userId();
        $user = $this->users->findById($userId) ?? throw new InvalidRecoverySecret();
        $user->ensureActive();

        $establishSession($user->email());

        try {
            $this->consume($userId, $selector, $secret, $now);
        } catch (AccountDeactivated|AccountSuspended $wall) {
            // **The session above is already committed, and the wall below cannot undo it.** The status
            // re-read under the lock refuses the CONSUMPTION; it does not refuse the session, because
            // `Security::login()` has already run its listeners — the `iam_session` row is inserted and
            // committed by one of them, and the native cookie is set. So an administrator suspending this
            // identity while the login was in flight would otherwise get a 403 body over a live, admitted
            // session, and the admission gate reads the session row and never the identity's status, so it
            // would keep working until it expired. Suspension is the containment against a leaked recovery
            // secret; that must not be the race an attacker retries until it lands.
            //
            // Compensating is the only close available: the two writes are in different transactions and no
            // ordering makes them one, so what remains is to undo the session once the wall is known. It runs
            // best-effort — a revoke that fails may not turn the 403 into a 500 — and it revokes EVERY
            // session of a walled identity, which is what the suspension itself would have done.
            $this->revokeSessions->revoke($userId);

            throw $wall;
        }

        // Post-commit, so the row can only describe a consumption that actually persisted — never a mere
        // verification, and never the loser of the race above.
        $this->audit->recordRedeemed($userId);
    }

    /**
     * The consuming half, in ONE transaction: take the user row, re-sample its status, re-read the secret
     * under the same lock and re-verify against THAT row, clear the lockout and retire the secret.
     *
     * Extracted from the caller so the status wall it can raise has somewhere to be caught — the session is
     * already committed by the time this runs, and the caller has to undo it.
     *
     * @throws InvalidRecoverySecret               when the row was retired or changed under the lock
     * @throws AccountSuspended|AccountDeactivated when the identity stopped being admissible
     */
    private function consume(
        string $userId,
        string $selector,
        #[SensitiveParameter]
        string $secret,
        DateTimeImmutable $now,
    ): void {
        $this->transactionManager->transactional(function () use ($userId, $selector, $secret, $now): void {
            // USER FIRST — by id, the same order minting takes and the order recording a failed login takes
            // on its own half. The re-sample refuses the CONSUMPTION for an identity an administrator walled
            // while the login was in flight; it does NOT refuse the session, which is already committed by
            // then — undoing that is the caller's compensating revoke, and saying otherwise here would be a
            // security claim this code does not make good on.
            $user = $this->users->findByIdForUpdate($userId) ?? throw new InvalidRecoverySecret();
            $user->ensureActive();

            // THEN the secret, and the verify is repeated against THIS row rather than trusted from the
            // resolving pass: a rival redemption or a revocation may have retired it in between, and the
            // whole point of the lock is that whatever is decided here is decided on the row nobody else can
            // move until this transaction ends.
            $liveSecret = $this->secrets->findBySelectorForUpdate($selector);

            if (!$liveSecret instanceof RecoverySecret || !$liveSecret->verify($secret, $now)) {
                throw new InvalidRecoverySecret();
            }

            $user->clearLockout();
            $liveSecret->redeem();

            $this->users->save($user);
            // The event is published INSIDE this transaction, so `DbalEventStore` appends it in the same
            // unit of work as the delete below: the record of the redemption cannot exist without the
            // consumption, and the consumption cannot commit without the record. That is what makes
            // "emitted only once the consumption is persisted" a property rather than an ordering habit —
            // the audit projection after the commit is best-effort and prunable, and could not carry it.
            $this->eventBus->publish(...$liveSecret->pullDomainEvents());
            $this->secrets->remove($liveSecret);
        });
    }

    /**
     * Splits the presented `<selector>.<secret>`. A malformed presentation dies as the same opaque refusal as
     * a wrong secret, so the wire shape reveals nothing about which half was wrong.
     *
     * @return array{string, string}
     */
    private function split(#[SensitiveParameter] string $presented): array
    {
        $parts = \explode('.', $presented, 2);

        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new InvalidRecoverySecret();
        }

        return [$parts[0], $parts[1]];
    }
}
