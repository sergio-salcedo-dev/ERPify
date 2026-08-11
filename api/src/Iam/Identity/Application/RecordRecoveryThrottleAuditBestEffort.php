<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects an exhausted recovery budget onto the operator's `security` surface — the half of #602 that is
 * otherwise invisible, because a throttled `POST /forgot-password` answers the same uniform 202 as a served
 * one and {@see \Erpify\Shared\Audit\Domain\AuditPolicy} audits `GET` only, so neither generic hook can see
 * a refusal that raises no exception and changes no response.
 *
 * NOTHING HERE MAY REACH THE RESPONSE, and every other decision follows from that. The write is swallowed
 * whole ({@see Throwable}, not {@see \Exception}: the audit writer encodes metadata with
 * `JSON_THROW_ON_ERROR`) because a `security` entry propagates by design in
 * {@see \Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger} — correct where a 403 becoming a 5xx is still
 * a refusal, and inadmissible here, where the uniform 202 is the control itself. The subject lookup sits
 * inside the same swallow for the same reason.
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
 * A resource-less row for an unresolved address is deliberate rather than a fallback: it keeps the signal of
 * a sweep against addresses that name nobody. The trail therefore lets an authorised reader tell a resolvable
 * target from an unresolvable one — stated rather than hidden, because withholding the row would carry the
 * same bit in its absence while losing the sweep.
 */
final readonly class RecordRecoveryThrottleAuditBestEffort
{
    private const string THROTTLED_ACTION = 'PASSWORD_RECOVERY_THROTTLED';

    public function __construct(
        private RecoveryThrottleAuditBudget $auditBudget,
        private UserRepository $users,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    public function record(string $email): void
    {
        if (!$this->auditBudget->claimFor($email)) {
            return;
        }

        try {
            $this->auditLogger->log(self::THROTTLED_ACTION, AuditLevel::SECURITY, $this->subjectOf($email));
        } catch (Throwable $throwable) {
            // The only signal that an observation was owed and not made: the claim is already spent, so this
            // address stays silent for the rest of the window. No id and no address in the line — this runs
            // once per address per window on an anonymous path the erasure chain does not reach.
            $this->logger->warning(
                'Recovery throttle exhausted; security audit projection skipped (write failed).',
                ['exception' => $throwable],
            );
        }
    }

    /**
     * The actor is `anonymous` by construction — no token exists at a forgot-password request — so the target
     * rides in the resource columns, which the erasure chain rewrites alongside `actor_id`. A malformed
     * address and an address matching no identity are the same answer here: no resource, no metadata, and in
     * particular no record of what was typed.
     */
    private function subjectOf(string $email): ?AuditResource
    {
        $canonicalEmail = Email::tryFrom($email);

        if (!$canonicalEmail instanceof Email) {
            return null;
        }

        $user = $this->users->findByEmail($canonicalEmail);

        if (!$user instanceof User) {
            return null;
        }

        $userId = $user->getId();

        return null === $userId ? null : AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId);
    }
}
