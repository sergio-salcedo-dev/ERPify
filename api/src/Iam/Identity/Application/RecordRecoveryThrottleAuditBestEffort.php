<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Projects an exhausted recovery budget onto the operator's `security` surface — the half that is
 * otherwise invisible: a throttled `POST /forgot-password` answers the same uniform 202 as a served
 * one and {@see \Erpify\Shared\Audit\Domain\AuditPolicy} audits `GET` only, so neither generic hook can see
 * a refusal that raises no exception and changes no response.
 *
 * NOTHING HERE ESCAPES — not the write, not the subject lookup or its lock, not the transaction, and not the
 * budget claim. A `security` entry propagates by design in
 * {@see \Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger}, which is correct where a 403 becoming a 5xx
 * is still a refusal and inadmissible on a path whose whole contract is a uniform answer.
 * The claim is inside the swallow because it can throw on its own account: a limiter configured with a limit
 * below one rejects every reservation outright, which would otherwise turn a misconfiguration into an
 * exception on exactly the refused requests and nowhere else.
 *
 * `Throwable` and not `\Exception` for the reason its sibling {@see RecordLockoutAuditBestEffort} gives — the
 * guarantee is survival against *anything* the projection can raise, and narrowing it to a hierarchy invites a
 * future collaborator to escape through the gap. It is not, as this class once claimed, because of
 * `JSON_THROW_ON_ERROR`: `JsonException` extends `\Exception` and both spellings catch it.
 *
 * IT IS GUARDED BY ITS OWN BUDGET AND CANNOT BE GUARDED BY THE ONE IT REPORTS. Past exhaustion every later
 * request in the window is refused too, so a row per refusal would be a synchronous INSERT per attempt while
 * somebody is hammering — *"a synchronous, per-attempt row on an unbudgeted endpoint is a write amplifier
 * handed to the attacker it is meant to record"*
 * ({@see \Erpify\Iam\Identity\Infrastructure\Http\InvalidCurrentPasswordAuditListener}). Its sibling
 * {@see RecordLockoutAuditBestEffort} needs no such guard: `User::recordFailedAttempt()` returns `false` while
 * the identity is already locked, so the lockout path stops opening transactions on its own. This path has no
 * such floor, because the throttle is precisely what is being reported.
 *
 * THE ROW NAMES THE SUBJECT WHEN THE ADDRESS RESOLVES, AND NOTHING WHEN IT DOES NOT. Naming it costs one
 * indexed read that the served branch already performs ({@see RequestPasswordReset}), so the two branches
 * converge rather than diverge, and it mints no new obligation: `User` is already the registry's person type
 * with an erasure owner, and `audit_log.resource_id` is already inside the reconciler's reach. What may never
 * appear, in `metadata` or anywhere else, is the ADDRESS — in clear, hashed or encoded. It is a person datum
 * whose erasure nothing owns: the anonymisers rewrite `actor_id` and `resource_id` and never touch
 * `metadata`, and every control holding `audit_log.metadata` free of person ids matches by id against
 * `identity_user`, so an address would be invisible to all of them and outlive its own subject.
 *
 * THE LOOKUP AND THE WRITE SHARE ONE TRANSACTION, AND THE LOOKUP HOLDS THE SUBJECT'S ROW. This runs from
 * `kernel.terminate`, well after anything else in the request, so an unlocked read could resolve an identity
 * whose erasure then commits and runs its pass over the trail before this INSERT lands — leaving the subject's
 * real id in `resource_id` with `resource_erased = FALSE`. {@see IdentityRowLock::whileHeldByEmail()} makes the
 * write wait for any erasure holding the row: once that commits, Postgres re-evaluates the locked read, finds
 * nothing, and the row is written without a resource, exactly as for an address that never named anyone. If this
 * side locks first, the erasure waits instead and its pass redacts the row it then finds committed. The budget
 * claim stays OUTSIDE the transaction and ahead of it: it is a limiter reservation, not a database write, and
 * spending it only once a write succeeded would let a failing trail retry the INSERT on every refused request —
 * the amplifier the budget exists to remove — and keep a lock wait on each of them.
 *
 * A resource-less row for an unresolved address is deliberate rather than a fallback: it keeps the signal of
 * a sweep against addresses that name nobody. The trail therefore lets an authorised reader tell a resolvable
 * target from an unresolvable one — stated rather than hidden, because withholding the row would carry the
 * same bit in its absence while losing the sweep.
 *
 * **The report goes to the `observability` channel — bound in `services.yaml`, since deptrac refuses this
 * layer a dependency on the container's attributes — and on this path the channel is the whole difference.**
 * On the default channel the report of the one thing this class exists to observe would be the thing nobody
 * could observe: prod routes that channel through `fingers_crossed`, which decides on the RECORD'S LEVEL and
 * not on the response, so anything below `error` is discarded when the request ends without one — and a
 * throttled `POST /forgot-password` ends in its uniform 202 by design. `observability` is always on, and
 * `monolog.yaml` excludes it from the buffered handler by name.
 *
 * The level is `error` for what it asserts rather than for whether it survives, which on this channel the
 * level does not decide: a `security` projection owed and not made is an integrity defect in the trail. The
 * pairing is what matters — raising the level without moving the channel would ACTIVATE the buffer and flush
 * whatever the request had accumulated, which is the opposite of what a class this careful about the address
 * should cause.
 *
 * The report itself is wrapped, via {@see ReportsAuditFailureSafely}: a catch whose entire purpose is that
 * nothing escapes may not throw, and the report call is real I/O.
 */
final readonly class RecordRecoveryThrottleAuditBestEffort
{
    use ReportsAuditFailureSafely;

    private const string THROTTLED_ACTION = 'PASSWORD_RECOVERY_THROTTLED';

    /** Where a failure raised by the budget claim itself is reported: ahead of any lock or write. */
    private const string PHASE_BUDGET = 'budget';

    public function __construct(
        private RecoveryThrottleAuditBudget $auditBudget,
        private IdentityRowLock $identityRows,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    public function record(#[SensitiveParameter] string $email): void
    {
        $phase = self::PHASE_BUDGET;

        try {
            if (!$this->auditBudget->claimFor($email)) {
                return;
            }

            $phase = self::PHASE_LOCK;
            $this->writeUnderTheSubjectsLock($email, $phase);
        } catch (Throwable $throwable) {
            // The only signal that an observation was owed and not made: the claim is already spent, so this
            // address stays silent for the rest of the window. No id and no address in the line — this runs
            // once per address per window on an anonymous path the erasure chain does not reach. The channel
            // is what makes the signal reachable at all; see the class docblock.
            $this->reportSafely(fn () => $this->logger->error(
                'Recovery throttle exhausted; security audit projection skipped.',
                ['phase' => $phase, 'exception' => $throwable],
            ));
        }
    }

    /**
     * An address with no canonical form — blank, or not valid UTF-8 — takes no lock: there is no row it could
     * resolve to. It still gets its row, without a resource, for the reason the class docblock gives.
     *
     * @param-out string $phase
     */
    private function writeUnderTheSubjectsLock(#[SensitiveParameter] string $email, string &$phase): void
    {
        $canonicalEmail = Email::tryFrom($email);

        if (!$canonicalEmail instanceof Email) {
            $phase = self::PHASE_WRITE;
            $this->auditLogger->log(self::THROTTLED_ACTION, AuditLevel::SECURITY);

            return;
        }

        $this->identityRows->whileHeldByEmail($canonicalEmail, function (?string $userId) use (&$phase): void {
            $phase = self::PHASE_WRITE;
            $this->auditLogger->log(self::THROTTLED_ACTION, AuditLevel::SECURITY, $this->subjectOf($userId));
            $phase = self::PHASE_COMMIT;
        });
    }

    /**
     * The actor is `anonymous` by construction — no token exists at a forgot-password request — so the target
     * rides in the resource columns, which the erasure chain rewrites alongside `actor_id`. An address matching
     * no identity is the same answer as a malformed one: no resource, no metadata, and in particular no record of
     * what was typed.
     */
    private function subjectOf(?string $userId): ?AuditResource
    {
        return null === $userId ? null : AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId);
    }
}
