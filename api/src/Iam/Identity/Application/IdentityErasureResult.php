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
 *
 * `erasedTotpScope` is the encryption scope of the second factor this run deleted, whose key it tombstoned or found
 * tombstoned, or `null`. It is carried so the compliance entry can name it: a destroyed key with no
 * `GDPR_SUBJECT_ERASED` entry carrying its scope is a divergence the subject-erasure reconciler reports for ever.
 * The two trailing members default so a result that erased no second factor reads as one.
 */
final readonly class IdentityErasureResult
{
    public function __construct(
        public string $userId,
        public bool $identityErased,
        public int $resetTokensDeleted,
        public int $recoverySecretsDeleted,
        public int $totpFactorsDeleted = 0,
        public ?string $erasedTotpScope = null,
    ) {
    }

    public function erasedAnything(): bool
    {
        return $this->identityErased
            || $this->resetTokensDeleted > 0
            || $this->recoverySecretsDeleted > 0
            || $this->totpFactorsDeleted > 0;
    }
}
