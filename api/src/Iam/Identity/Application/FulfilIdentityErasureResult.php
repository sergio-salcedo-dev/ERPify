<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Outcome of {@see FulfilIdentityErasure}: what the chained erasure actually removed across the identity,
 * the trail, the reproducible business log, the sessions, the organization membership and the invitations
 * addressed to the subject. The two credential-recovery counts are reported apart for the reason
 * {@see IdentityErasureResult} gives: one table lapses within the hour, the other holds a decade.
 * `identityErased` is the surface-neutral fact each caller reads differently — the
 * HTTP controller turns `false` into a 404, the CLI turns it into an idempotent "nothing to erase" — while
 * the counts feed the operator's report and the compliance self-audit.
 *
 * The three anonymisation counts are separate because they are different row sets answering different
 * questions: `anonymizedAuditRows` are trail rows the subject **authored**, `anonymizedResourceRows` are
 * trail rows that **name** them, `anonymizedEventRows` are business-log rows carrying the identifier in
 * either of that table's two axes. Summing any of them would merge GDPR axes that
 * `docs/adr/regulatory-audit-trail.md` D15 keeps distinct, and would silently redefine a figure already
 * published in `GDPR_ERASURE_EXECUTED` and printed by the CLI.
 *
 * `anonymizedEventRows` is the one disjunct of {@see erasedAnything()} that cannot decide the answer on its
 * own: the business-log pass runs only for a subject whose identity was live, so a positive count there
 * implies `identityErased`. It is kept in the disjunction rather than dropped because the method states a
 * property of the whole result — "this erasure removed something" — and a term that is currently implied is
 * cheaper to leave than to rediscover the day that pass gains a second trigger.
 */
final readonly class FulfilIdentityErasureResult
{
    /**
     * What the operator is consenting to, one entry per promoted constructor property, in constructor
     * order — which is NOT the order the chain runs in (the invitation purge leads, and that position is
     * load-bearing; {@see FulfilIdentityErasure} argues why). The map lives
     * beside the properties because an enumeration kept anywhere else drifts from them silently: the
     * recovery-secret link was added to this DTO and to the CLI's success line while three prose
     * descriptions of the same chain kept naming eight links, so the confirmation prompt asked consent for
     * less than the erasure destroys. A surface that asks or explains renders this map rather than writing
     * its own sentence, and {@see FulfilIdentityErasureResultTest} holds the two sets equal in both
     * directions, so a tenth property cannot arrive without its label.
     *
     * It does not reach the prose in a docblock — that stays a review matter — and it says nothing about
     * whether a label describes its property correctly.
     *
     * @var array<string, string>
     */
    public const array ERASED_CATEGORIES = [
        'identityErased' => 'the identity itself, its email and its credential hash',
        'resetTokensDeleted' => 'every pending password-reset token',
        'recoverySecretsDeleted' => 'its administrative recovery secret',
        'anonymizedAuditRows' => 'the audit rows it authored',
        'anonymizedResourceRows' => 'the audit rows that name it',
        'anonymizedEventRows' => 'its identifier inside the business event log',
        'sessionsDeleted' => 'its sessions',
        'membershipsDeleted' => 'its organization membership',
        'invitationsDeleted' => 'every invitation addressed to it',
    ];

    public function __construct(
        public bool $identityErased,
        public int $resetTokensDeleted,
        public int $recoverySecretsDeleted,
        public int $anonymizedAuditRows,
        public int $anonymizedResourceRows,
        public int $anonymizedEventRows,
        public int $sessionsDeleted,
        public int $membershipsDeleted,
        public int $invitationsDeleted,
    ) {
    }

    public function erasedAnything(): bool
    {
        return $this->identityErased
            || $this->resetTokensDeleted > 0
            || $this->recoverySecretsDeleted > 0
            || $this->anonymizedAuditRows > 0
            || $this->anonymizedResourceRows > 0
            || $this->anonymizedEventRows > 0
            || $this->sessionsDeleted > 0
            || $this->membershipsDeleted > 0
            || $this->invitationsDeleted > 0;
    }
}
