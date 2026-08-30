<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Repository;

use Erpify\Organization\Membership\Domain\Entity\Membership;
use UnexpectedValueException;

/**
 * Aggregate-lifecycle port for {@see Membership}.
 *
 * {@see MembershipRepository::findByUserId()} enforces the one-membership-per-user invariant at write time
 * (a caller rejects a second grant for a user that already belongs). {@see findByOrganizationId()} lists an
 * organization's members; it does NOT back the "at least one active ADMIN" invariant, which is resolved from
 * the identity aggregate — roles live on `identity_user`, and a membership carries no status with which to
 * express liveness.
 */
interface MembershipRepository
{
    public function save(Membership $membership): void;

    public function remove(Membership $membership): void;

    /**
     * Hard-deletes the user's membership and returns how many rows went. A membership is a pure link — the
     * user it binds and the organization it binds them to — so once the person is gone there is nothing left
     * in the row that means anything, and no state worth keeping.
     *
     * `user_id` is UNIQUE, so this removes at most one row; it is phrased as a count rather than a boolean
     * because the erasure that consumes it reports counts, and because the uniqueness is a schema fact this
     * port should not have to promise. Idempotent — a second pass over a user with no membership deletes
     * nothing and returns 0.
     *
     * @throws UnexpectedValueException when the store yields no affected-row count
     */
    public function deleteAllForUser(string $userId): int;

    public function findByUserId(string $userId): ?Membership;

    /**
     * @return list<Membership>
     */
    public function findByOrganizationId(string $organizationId): array;
}
