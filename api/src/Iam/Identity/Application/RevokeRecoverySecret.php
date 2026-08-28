<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Destroys the caller's own recovery secret. It is the explicit, visible eviction this design owes its users
 * in exchange for never destroying a secret silently: a password change leaves a live secret standing, so
 * revocation is the only way a holder stops being one short of redeeming it or waiting out the decade.
 *
 * Idempotent by construction — an identity with no secret is a successful, empty revocation. There is nothing
 * disclosed by saying so: the caller is the owner, holds a session, and the profile surface already tells them
 * whether a secret exists.
 *
 * The row is read `FOR UPDATE` and only then removed, rather than deleted by predicate. That costs one extra
 * round trip and buys the guarantee the concurrency matrix names: a redemption in flight holds this same row,
 * so the two serialise on it and the loser is a plain no-op — never a revocation landing between a
 * redemption's verify and its consume, which is the one interleaving that could retire a row the redemption
 * had already decided about.
 *
 * It takes NO lock on the user row, and that asymmetry with minting and redemption is deliberate rather than
 * an omission: this flow never touches the lockout, so it has no second lock to order against the first, and
 * a path holding exactly one lock cannot be part of a deadlock cycle.
 *
 * Whether a row was actually removed is reported so the audit projection describes what happened rather than
 * what was asked for — a revocation of nothing is not a revocation, and recording one would put a security
 * row in the trail for an act that never took place.
 */
final readonly class RevokeRecoverySecret
{
    public function __construct(
        private RecoverySecretRepository $secrets,
        private RecordRecoverySecretAuditBestEffort $audit,
        private TransactionManager $transactionManager,
    ) {
    }

    public function revoke(string $userId): void
    {
        $revoked = $this->transactionManager->transactional(function () use ($userId): bool {
            $secret = $this->secrets->findByUserIdForUpdate($userId);

            if (!$secret instanceof RecoverySecret) {
                return false;
            }

            $this->secrets->remove($secret);

            return true;
        });

        // Post-commit and best-effort, for the same reason the other two projections are: the secret is
        // already destroyed and unrecoverable, so a failing audit write may not answer the caller with a 500
        // that says their revocation did not happen.
        if ($revoked) {
            $this->audit->recordRevoked($userId);
        }
    }
}
