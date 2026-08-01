<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Application;

use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Hard-deletes the user's membership — the published seam a GDPR identity erasure consumes so the link that
 * brought the person into the organization does not outlive them. `membership.user_id` is an id with no constraint
 * behind it — the reference crosses a bounded context, so integrity is by id — and nothing in the schema
 * references `identity_user`, so deleting that row cascades nowhere and leaves this one pointing at an id that no
 * longer resolves.
 *
 * It runs directed through the repository, so when the caller wraps it in a transaction — the erasure does —
 * the DELETE joins that unit of work and rolls back with it. It emits no event: an erased subject needs no
 * "membership revoked" signal, and a membership is a pure link with nothing left to observe once the person
 * is gone.
 */
final readonly class PurgeUserMembership
{
    public function __construct(
        private MembershipRepository $memberships,
    ) {
    }

    public function purge(string $userId): int
    {
        Uuid::ensure($userId);

        return $this->memberships->deleteAllForUser($userId);
    }
}
