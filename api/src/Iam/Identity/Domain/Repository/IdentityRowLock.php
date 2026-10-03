<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

/**
 * Holds one subject's `identity_user` row for the rest of the caller's transaction, and says whether there was
 * a row to hold.
 *
 * It is the serialisation point between the identity erasure and every write about the subject that lands
 * outside the erasure's own transaction. The erasure holds this row under `FOR UPDATE` from its locking
 * administrator check to its commit, and deletes it, so a writer that takes the same row first either commits
 * before the erasure's audit passes run — and its row is rewritten by them — or waits for the erasure to
 * commit and then finds no row at all. There is no third interleaving, which is the whole reason the lock is
 * on this row rather than on anything the audit module owns.
 *
 * A port of its own rather than {@see UserRepository::findByIdForUpdate()}: the question is "is the row live,
 * and hold it", never "load the aggregate". Hydrating answers it with a refresh of whatever instance the
 * caller's unit of work already manages, which a write that runs after the caller has committed has no
 * business touching.
 */
interface IdentityRowLock
{
    /**
     * Takes the subject's row `FOR UPDATE` and answers whether it exists. `false` means the identity is gone —
     * never created, or erased — and nothing was locked.
     *
     * **Must run inside the caller's transaction.** Postgres releases a `FOR UPDATE` at the end of the
     * statement that took it, so outside one this would answer correctly and hold nothing by the time the
     * caller writes; the implementation refuses rather than complying silently.
     */
    public function lockIfLive(string $userId): bool;
}
