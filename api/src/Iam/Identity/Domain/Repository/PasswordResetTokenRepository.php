<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use UnexpectedValueException;

/**
 * Aggregate-lifecycle port for {@see PasswordResetToken}.
 *
 * {@see findById()} is the selector half of the selector-verifier link (`<id>.<secret>`): the id picks the row
 * and the caller then verifies the secret against its digest, so no hash-index lookup is needed.
 * {@see deleteAllForUser()} supersedes a user's pending request when a fresh one is issued, and {@see consume()}
 * is the retire in the consumer's retire-then-act — a conditional delete whose affected-row count is the
 * single-use guard.
 */
interface PasswordResetTokenRepository
{
    public function save(PasswordResetToken $token): void;

    /**
     * Consumes the token as the retire in retire-then-act: a conditional delete that returns whether a row was
     * actually removed. It is the single-use guard — two concurrent completions serialise on the row lock, so
     * only the first deletes a row (returns `true`) and the loser sees it already gone (returns `false`) and
     * must abort. A token can therefore drive at most one reset even under a concurrent replay.
     *
     * `false` means the row was gone, and only that. An implementation that cannot tell how many rows it
     * removed raises rather than answering `false`: aborting is right either way, but telling a live token's
     * holder that it was already spent is a diagnosis they cannot act on and whose retry fails identically.
     *
     * @throws UnexpectedValueException when the store yields no affected-row count
     */
    public function consume(PasswordResetToken $token): bool;

    /**
     * Returns the token by id, or `null` when no such row exists — including when `$id` is not a well-formed
     * UUID (a malformed selector can key no row), so the caller never has to pre-validate the selector shape.
     */
    public function findById(string $id): ?PasswordResetToken;

    /**
     * Drops every pending reset token of the user — called before issuing a new one so only the latest request
     * is live (a re-request supersedes its predecessor), and by the subject erasure so no `user_id` linkage
     * outlives the identity.
     *
     * **What it promises is about PERSISTED state, and read-after-delete inside one unit of work is
     * undefined.** The removal is a bulk statement, so an implementation is free to leave an already-hydrated
     * instance readable through {@see findById()} until the unit of work ends — the Doctrine adapter does
     * exactly that, because a DQL bulk `DELETE` does not evict the identity map that `EntityManager::find()`
     * consults. No caller re-reads within the same transaction, and none may start: a test asserting the row
     * is gone before the commit would be asserting a guarantee this port deliberately does not make, and
     * every future adapter would owe identity-map gymnastics for it.
     *
     * @throws UnexpectedValueException when the store yields no affected-row count
     *
     * @return int the number of rows removed — this IS promised, and it is what a caller may act on
     */
    public function deleteAllForUser(string $userId): int;

    /**
     * Retention sweep: drops every token whose `expires_at` has passed. An expired row is already dead to the
     * verifier, so keeping it buys nothing and the table would otherwise grow without bound.
     *
     * Carries the same weak contract as {@see deleteAllForUser()} and for the same reason — it is the same
     * DQL bulk `DELETE`, so an already-hydrated instance may stay readable through {@see findById()} until
     * the unit of work ends. The count is the promise; the disappearance is not.
     *
     * @throws UnexpectedValueException when the store yields no affected-row count
     *
     * @return int the number of rows removed — this IS promised, and it is what a caller may act on
     */
    public function deleteExpired(DateTimeImmutable $now): int;
}
