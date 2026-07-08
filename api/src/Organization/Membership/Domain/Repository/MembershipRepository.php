<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Repository;

use Erpify\Organization\Membership\Domain\Entity\Membership;

/**
 * Aggregate-lifecycle port for {@see Membership}.
 *
 * {@see MembershipRepository::findByUserId()} enforces the one-membership-per-user invariant at write time
 * (a caller rejects a second grant for a user that already belongs). {@see findByOrganizationId()} backs the
 * "the organization always keeps at least one active ADMIN" domain invariant — a read the bootstrap satisfies
 * and later stories verify against member-lifecycle changes.
 */
interface MembershipRepository
{
    public function save(Membership $membership): void;

    public function remove(Membership $membership): void;

    public function findByUserId(string $userId): ?Membership;

    /**
     * @return list<Membership>
     */
    public function findByOrganizationId(string $organizationId): array;
}
