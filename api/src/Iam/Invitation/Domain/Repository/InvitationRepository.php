<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Repository;

use Erpify\Iam\Invitation\Domain\Entity\Invitation;

/**
 * Aggregate-lifecycle port for {@see Invitation}.
 *
 * The accept flow localises the invitation by its id — the emailed token is a `<invitationId>.<secret>`
 * selector-verifier, so the id selects the row and the aggregate's {@see Invitation::verify()} checks the
 * secret. That keeps the token digest opaque (the value object never exposes a lookup-by-hash) and needs no
 * secondary index on the hash.
 */
interface InvitationRepository
{
    public function save(Invitation $invitation): void;

    public function findById(string $id): ?Invitation;

    /**
     * Loads the invitation under a pessimistic write lock, so two concurrent accepts of the same single-use
     * token serialise on the row: the second caller blocks until the first commits, then re-reads the retired
     * (`ACCEPTED`) status and is rejected. A plain read cannot enforce single-use across two connections — the
     * status IS the gate, and it must be checked and flipped under a held lock. Must run inside a transaction.
     */
    public function findByIdForUpdate(string $id): ?Invitation;

    /**
     * Every still-revocable invitation addressed to the user, under a pessimistic write lock. `SENT` is the
     * only revocable state — `CREATED` has not been delivered and the three terminal states are spent — so the
     * predicate is the filter, not a caller-side check.
     *
     * The lock is what makes revoking several rows survivable under a concurrent accept. Without it the
     * invitee retiring one of them mid-flight would make the aggregate refuse its `SENT → REVOKED` transition
     * and roll the whole operation back, so an incident responder pulling live tokens would revoke NOTHING.
     * `FOR UPDATE` under READ COMMITTED re-evaluates the predicate after the lock is granted, so a row that
     * became `ACCEPTED` simply leaves the set and every other invitation is still revoked. Must run inside a
     * transaction; several rows may come back, because nothing constrains a user to one invitation.
     *
     * @return list<Invitation>
     */
    public function findSentByInvitedUserForUpdate(string $userId): array;

    /**
     * Hard-deletes every invitation addressed to the user — whatever its status — and returns how many rows
     * went. A terminal `ACCEPTED`, `REVOKED` or `EXPIRED` invitation still carries the invited person's id,
     * so the lifecycle state does not change that the column is personal data and none of them may be spared.
     * The row is deleted rather than scrubbed: it also holds a single-use credential digest, and there is no
     * meaning left in a delivery record for somebody who no longer exists.
     *
     * Unlike its sibling on the membership port this can remove several rows: nothing constrains a user to
     * one invitation. Idempotent — a second pass over a user with none deletes nothing and returns 0.
     */
    public function deleteAllForInvitedUser(string $userId): int;
}
