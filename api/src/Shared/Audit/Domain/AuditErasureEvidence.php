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
 * **What an exempt row keeps for ever, stated in full because the exemption is what makes it immortal.**
 * These rows are minted through {@see \Erpify\Shared\Audit\Infrastructure\SealedAuditEntryFactory}, which
 * seals request metadata onto **every** entry, so an erasure executed over HTTP writes the acting
 * administrator's `ip` and `user_agent` alongside their `actor_id` — and the resource-axis anonymiser
 * deliberately leaves those two alone wherever an actor was identified, because they are that
 * administrator's data and not the subject's. So the exemption retains all three indefinitely, not just the
 * id: exactly the triple `docs/rules/database.md` names as the PII whose bounded window *is* the
 * minimisation. There is a removal path and it is **discretionary**: the actor-axis erasure matches
 * `actor_id`, so these columns clear only if that administrator is themself erased. The operator CLI paths
 * are unaffected — they run off-request as `system`, with both columns null.
 *
 * **Weighed and accepted, not overlooked.** The two actions do not have the same correct retention, only
 * the same floor of "longer than 365 days". `SUBJECT_ERASED` needs never, because its falsifier is eternal.
 * `ACTOR_TRAIL_ERASED` answers for `actor_erased` marks on `change` rows, which are themselves pruned at
 * five years — so retaining it for ever over-retains the acting administrator past the point anything could
 * check it against. Both are exempt anyway. The two narrower shapes were considered and declined: a bounded
 * floor for `ACTOR_TRAIL_ERASED` splits one rule into two for a difference no reader would infer from the
 * rows, and keeping request metadata off these rows at write time would strip attribution from the one class
 * of row whose purpose *is* attribution — an erasure nobody can place is weaker evidence than an
 * over-retained address is a harm. The exposure is bounded in practice: administrators are few, the removal
 * path exists through their own erasure, and the CLI paths write both columns null.
 *
 * **Revisit trigger**, so the acceptance does not become invisible: the first of a production deployment
 * that erases a real subject, an administrator who leaves and is not erased, or a DPO review — whichever
 * comes first. At that point the choice is the bounded floor, and this paragraph is the record that it was
 * a choice.
 */
final class AuditErasureEvidence
{
    /** Written by the identity erasure and the operator CLI: the actor axis was anonymised. */
    public const string ACTOR_TRAIL_ERASED = 'GDPR_ERASURE_EXECUTED';

    /** Written where a subject's data encryption key was destroyed: the row the reconciler joins against. */
    public const string SUBJECT_ERASED = 'GDPR_SUBJECT_ERASED';

    /**
     * @var list<string>
     */
    public const array ACTIONS = [self::ACTOR_TRAIL_ERASED, self::SUBJECT_ERASED];

    /**
     * A namespace for the closed set of evidence actions, never a value.
     */
    private function __construct()
    {
    }
}
