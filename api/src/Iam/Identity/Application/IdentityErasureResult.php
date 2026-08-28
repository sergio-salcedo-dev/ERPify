<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Outcome of {@see EraseIdentitySubject}, so the CLI can report what a run actually removed and an
 * idempotent re-run (nothing live, nothing pending) can skip the self-audit.
 *
 * The two credential-recovery counts stay separate rather than summing into one "artefacts removed": they
 * are different tables with different lifetimes — a reset token dies within the hour, a recovery secret has
 * a ten-year TTL and no sweep — and an operator reading a single figure could not tell which of them a
 * surprising number came from.
 */
final readonly class IdentityErasureResult
{
    public function __construct(
        public string $userId,
        public bool $identityErased,
        public int $resetTokensDeleted,
        public int $recoverySecretsDeleted,
    ) {
    }

    public function erasedAnything(): bool
    {
        return $this->identityErased
            || $this->resetTokensDeleted > 0
            || $this->recoverySecretsDeleted > 0;
    }
}
