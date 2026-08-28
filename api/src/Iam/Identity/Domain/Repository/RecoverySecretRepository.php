<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;

/**
 * Aggregate-lifecycle port for {@see RecoverySecret}.
 *
 * The lookups come in pairs, and the split is the invariant rather than a convenience. The plain finders
 * RESOLVE and authorize nothing; the `ForUpdate` finders are the only ones that may carry a decision to
 * consume or to revoke, and they re-hydrate from the locked row. Keeping the resolving lookup lock-free is
 * deliberate: a finder that locked implicitly would eventually be reused in the wrong order by a caller that
 * did not know it was taking a lock at all, and the ordering below is what makes the absence of a deadlock
 * demonstrable rather than hoped for.
 *
 * The lock order is USER FIRST, THEN SECRET, on every path that takes both. Redemption resolves the selector
 * unlocked, takes the user row by the `userId` that resolution yielded, and only then locks the secret;
 * minting takes the same pair in the same order. Revocation takes the secret alone and no user, which cannot
 * close a cycle. The path that mutates the lockout from the other side — recording a failed login — takes the
 * user row and never touches this table, so no acquisition anywhere reaches the secret ahead of the user.
 */
interface RecoverySecretRepository
{
    public function save(RecoverySecret $secret): void;

    public function remove(RecoverySecret $secret): void;

    /**
     * Resolves the selector half of a presented `<selector>.<secret>` WITHOUT locking and without deciding
     * anything: its result may be read for the `userId` it names, never verified against and consumed. A
     * malformed selector answers `null` rather than raising — it can key no row, so the caller's uniform
     * refusal stays uniform and a non-UUID value never reaches Postgres as a cast error.
     */
    public function findBySelector(string $selector): ?RecoverySecret;

    /**
     * Re-reads the row under `SELECT … FOR UPDATE`. Everything the decision rests on — existence, expiry, the
     * constant-time verify — is asserted on THIS instance, so two concurrent redemptions cannot both verify a
     * row neither has yet retired. Callable only inside a transaction, and only after the user row is held.
     */
    public function findBySelectorForUpdate(string $selector): ?RecoverySecret;

    /**
     * The owner's own secret, unlocked: the profile read, which decides nothing.
     */
    public function findByUserId(string $userId): ?RecoverySecret;

    /**
     * The owner's own secret under `SELECT … FOR UPDATE` — the read a mint refusal or a revocation is decided
     * on. Callable only inside a transaction.
     */
    public function findByUserIdForUpdate(string $userId): ?RecoverySecret;

    /**
     * Drops the subject's secret outright — the GDPR erasure, which is the only path that removes a row it
     * has not first decided about, because the person it references is going away with it.
     *
     * @return int the number of rows removed
     */
    public function deleteAllForUser(string $userId): int;
}
