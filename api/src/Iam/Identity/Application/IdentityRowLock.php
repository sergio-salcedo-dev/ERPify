<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Email;
use SensitiveParameter;

/**
 * Runs an operation in a transaction of its own that holds one identity's `identity_user` row — and does
 * nothing else to it. It is the serialisation the post-commit audit writers owe an erasure, and nothing more.
 *
 * A row naming a natural person in `audit_log.resource_id` that commits after {@see FulfilIdentityErasure} has
 * run its pass over the trail is residue nothing clears: the pass redacts only what is committed when it runs.
 * Holding the subject's row while the INSERT is made puts the write on one side of the erasure or the other.
 * Lock first, and the erasure waits on the row and then finds this row committed and redacts it. Arrive second,
 * and the locked read — re-evaluated by Postgres once the erasure commits — finds no row, so the operation is
 * not run at all. The order is `identity_user` → `audit_log`, the erasure's own, so the two cannot deadlock.
 *
 * **Why a port of its own rather than {@see \Erpify\Iam\Identity\Domain\Repository\UserRepository}'s locked
 * finders.** Those hydrate a `User` under a refresh hint, which overwrites whatever unflushed state a managed
 * aggregate of the same identity holds in the request's entity manager, and they need a transaction that
 * {@see \Erpify\Shared\Persistence\Application\TransactionManager} opens by flushing the whole pending unit of
 * work. A writer that only wants the row held for the duration of one INSERT should do neither, so this port
 * locks with a bare `SELECT … FOR UPDATE`, opens its transaction on the connection rather than through the
 * entity manager, and never touches the identity map. A failure inside it rolls back only this transaction and
 * leaves the entity manager open.
 *
 * **The wait is bounded.** Every caller swallows its failure, so the right answer to a long-held row is a
 * reported, skipped projection — never a login refusal held open, or a worker stalled, for the whole of an
 * erasure transaction. What the bound is and where it applies is the adapter's to state.
 */
interface IdentityRowLock
{
    /**
     * Holds the identity's row, then runs `$operation` inside that same transaction and reports `true`. An id
     * that names no row — never existed, or erased before the lock was granted — runs nothing and reports
     * `false`: the identity is owed no row naming it.
     *
     * @param callable(): void $operation
     */
    public function whileHeld(string $userId, callable $operation): bool;

    /**
     * Resolves the address to an identity under the same row lock and runs `$operation` inside that
     * transaction with the id it resolved, or with `null` when the address names nobody. Unlike
     * {@see whileHeld()} the operation always runs: its caller writes a row either way, only with or without a
     * subject.
     *
     * @param callable(?string): void $operation
     */
    public function whileHeldByEmail(#[SensitiveParameter] Email $email, callable $operation): void;
}
