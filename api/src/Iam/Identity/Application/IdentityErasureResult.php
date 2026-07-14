<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Outcome of {@see EraseIdentitySubject}, so the CLI can report what a run actually removed and an
 * idempotent re-run (nothing live, nothing pending) can skip the self-audit.
 */
final readonly class IdentityErasureResult
{
    public function __construct(
        public string $userId,
        public bool $identityErased,
        public int $resetTokensDeleted,
    ) {
    }

    public function erasedAnything(): bool
    {
        return $this->identityErased || $this->resetTokensDeleted > 0;
    }
}
