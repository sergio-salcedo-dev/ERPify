<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Repository\IdentityRowLock;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Runs a write about one subject in its own transaction, behind a lock on that subject's `identity_user` row,
 * and skips it when the row is gone.
 *
 * **It exists for the audit projections that land after their use case has committed.** Each `*BestEffort`
 * recorder in this module names the subject as its audit resource and runs post-commit by decision, so none
 * of them shares a transaction with anything that holds the subject. {@see FulfilIdentityErasure} rewrites
 * the trail with UPDATEs that match the rows existing when they run, and holds the subject's row from its
 * locking administrator check until it commits; a projection committing in between would keep the real id in
 * `resource_id` with `resource_erased = FALSE`, and the requester's address with it, for ever — recoverable
 * only by someone re-running the erasure on the reconciler's word. Contending on the same row removes the
 * window instead of narrowing it: the write either commits before the erasure's passes run, and they rewrite
 * it like any other row, or it waits for the erasure to commit and then finds nothing to name.
 *
 * Skipping is the only correct answer to an absent row, not a degraded one. A row naming a person who no
 * longer exists is exactly what the erasure exists to leave behind nowhere, and there is nobody for an
 * operator to act on.
 *
 * **The lock is taken before the write, never after**, and inside the same transaction: taken after, the row
 * would already be inserted by the time the erasure could be seen, and taken in another transaction it would
 * be released before the insert committed. A failure anywhere — the lock, the write, the commit — is the
 * caller's to swallow, exactly as a failed write was before it ran under a lock.
 */
final readonly class IdentityRowSerialiser
{
    public function __construct(
        private IdentityRowLock $rows,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @param callable(): void $write
     *
     * @return bool whether the subject was live and the write ran
     */
    public function whileLive(string $userId, callable $write): bool
    {
        return $this->transactionManager->transactional(function () use ($userId, $write): bool {
            if (!$this->rows->lockIfLive($userId)) {
                return false;
            }

            $write();

            return true;
        });
    }
}
