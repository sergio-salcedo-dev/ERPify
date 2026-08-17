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
 * Projects an exhausted recovery budget onto the operator's `security` surface — the half that is
 * otherwise invisible: a throttled `POST /forgot-password` answers the same uniform 202 as a served
 * one and {@see \Erpify\Shared\Audit\Domain\AuditPolicy} audits `GET` only, so neither generic hook can see
 * a refusal that raises no exception and changes no response.
 *
 * NOTHING HERE ESCAPES — not the write, not the subject lookup, and not the budget claim. A `security` entry
 * propagates by design in {@see \Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger}, which is correct where
 * a 403 becoming a 5xx is still a refusal and inadmissible on a path whose whole contract is a uniform answer.
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
        try {
            if (!$this->auditBudget->claimFor($email)) {
                return;
            }

            $this->auditLogger->log(self::THROTTLED_ACTION, AuditLevel::SECURITY, $this->subjectOf($email));
        } catch (Throwable $throwable) {
            // The only signal that an observation was owed and not made: the claim is already spent, so this
            // address stays silent for the rest of the window. No id and no address in the line — this runs
            // once per address per window on an anonymous path the erasure chain does not reach. The channel
            // is what makes the signal reachable at all; see the class docblock.
            $this->logger->error(
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
