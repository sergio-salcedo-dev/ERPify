<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The audit actions that record that a GDPR erasure was executed — the trail's own proof of its own
 * compliance, and the one class of row the retention prune may never take.
 *
 * **The rule is not "compliance rows are precious". It is that evidence may not expire before the thing it
 * attests.** `GDPR_SUBJECT_ERASED` answers for a `dek_keystore` tombstone that {@see
 * \Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore::destroy()} keeps for ever on purpose, so a
 * shredding stays reconcilable. {@see \Erpify\Shared\Audit\Application\SubjectErasureReconciler} anti-joins
 * the two with no date bound. Let the evidence age out under the `security` ceiling and that pair stops
 * being satisfiable: from its own +365 days every correctly-executed erasure reports as an integrity
 * divergence, daily and for ever, and the alarm becomes indistinguishable from retention doing its job.
 *
 * **Why a list of actions and not a retention level.** The evidence is discriminated by `action` because
 * that is what its detective control reads. Discriminating it by `level` instead would leave the pruner and
 * the reconciler keying on different columns, free to drift apart in silence — which is the shape of the
 * defect this exists to close, not a fix for it. A fourth {@see AuditLevel} would also duplicate an existing
 * case on one axis or the other: the enum fixes **how a row is written** (async, sync-before-response, or
 * inside the writing transaction) as much as how long it lives, and evidence introduces no new write mode.
 *
 * **This centralises the tokens, so the production writers and the prune cannot disagree about what an
 * evidence row is.** A drifted copy here does not read as a typo; it silently returns a row to the sweep. That is the
 * same argument that earned {@see AuditRedaction} its extraction, and it is why the acceptance test spells
 * these literals out by hand instead of importing them.
 *
 * **What the exemption keeps, and what it does not.** These rows are minted through
 * {@see \Erpify\Shared\Audit\Infrastructure\SealedAuditEntryFactory}, which seals request metadata onto
 * **every** entry, so an erasure executed over HTTP writes the acting administrator's `ip` and `user_agent`
 * beside their `actor_id`. The row and its `actor_id` are kept for ever, because the attribution is what the
 * evidence is for. The other two are not: once the row is past the threshold of its own level — the same
 * {@see AuditRetentionThreshold} the prune's `DELETE` applies there — the same run sets both to `NULL`, so the
 * window that minimises every other row's request metadata reaches these too. `NULL` rather than
 * {@see AuditRedaction::SENTINEL}, because retention is not an erasure axis, and a row the actor-axis erasure
 * already resolved keeps that pass's sentinel. Inside the window the only removal path is that erasure, which
 * matches by `actor_id`; the identity route to it refuses a subject still carrying `ADMIN`, while the operator
 * CLI `audit:gdpr:erase` carries no role check. The CLI paths write both columns `NULL` on the rows they mint,
 * running off-request as `system`.
 *
 * Why the two actions share one exemption although their correct retention differs, and what reopens the
 * choice of threshold, is `docs/adr/audit-activity-log.md` D4.
 */
final class AuditErasureEvidence
{
    /** Written by the identity erasure and the operator CLI: the actor axis was anonymised. */
    public const string ACTOR_TRAIL_ERASED = 'GDPR_ERASURE_EXECUTED';

    /** Written where a subject's data encryption key was destroyed: the row the reconciler joins against. */
    public const string SUBJECT_ERASED = 'GDPR_SUBJECT_ERASED';

    /**
     * Declared non-empty because {@see AuditRetentionThreshold} requires it:
     * emptying this constant would otherwise be caught only by PHPStan inferring the literal, not by the
     * contract.
     *
     * @var non-empty-list<string>
     */
    public const array ACTIONS = [self::ACTOR_TRAIL_ERASED, self::SUBJECT_ERASED];

    /**
     * A namespace for the closed set of evidence actions, never a value.
     */
    private function __construct()
    {
    }
}
