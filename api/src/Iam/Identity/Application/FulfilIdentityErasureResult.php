<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Outcome of {@see FulfilIdentityErasure}: what the chained erasure actually removed across identity, trail
 * and sessions. `identityErased` is the surface-neutral fact each caller reads differently — the HTTP
 * controller turns `false` into a 404, the CLI turns it into an idempotent "nothing to erase" — while the
 * counts feed the operator's report and the compliance self-audit.
 */
final readonly class FulfilIdentityErasureResult
{
    public function __construct(
        public bool $identityErased,
        public int $resetTokensDeleted,
        public int $anonymizedAuditRows,
        public int $sessionsDeleted,
    ) {
    }

    public function erasedAnything(): bool
    {
        return $this->identityErased
            || $this->resetTokensDeleted > 0
            || $this->anonymizedAuditRows > 0
            || $this->sessionsDeleted > 0;
    }
}
