<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Outcome of {@see FulfilIdentityErasure}: what the chained erasure actually removed across the identity,
 * the trail, the reproducible business log, the sessions, the organization membership and the invitations
 * addressed to the subject. `identityErased` is the surface-neutral fact each caller reads differently — the
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
    public function __construct(
        public bool $identityErased,
        public int $resetTokensDeleted,
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
            || $this->anonymizedAuditRows > 0
            || $this->anonymizedResourceRows > 0
            || $this->anonymizedEventRows > 0
            || $this->sessionsDeleted > 0
            || $this->membershipsDeleted > 0
            || $this->invitationsDeleted > 0;
    }
}
