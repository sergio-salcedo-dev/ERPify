<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Outcome of {@see FulfilIdentityErasure}: what the chained erasure actually removed across the identity,
 * the trail, the sessions, the organization membership and the invitations addressed to the subject.
 * `identityErased` is the surface-neutral fact each caller reads differently — the HTTP
 * controller turns `false` into a 404, the CLI turns it into an idempotent "nothing to erase" — while the
 * counts feed the operator's report and the compliance self-audit.
 *
 * The two trail counts are separate because they are different row sets answering different questions:
 * `anonymizedAuditRows` are rows the subject **authored**, `anonymizedResourceRows` are rows that **name**
 * them. Summing them would merge the two GDPR axes that `docs/adr/regulatory-audit-trail.md` D15 keeps
 * distinct, and would silently redefine a figure already published in `GDPR_ERASURE_EXECUTED` and printed
 * by the CLI.
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
