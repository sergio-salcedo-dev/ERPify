<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

/**
 * Consumer-owned port answering the single question the status-change use case must ask before it takes an
 * identity out of the active set: would the organization still keep at least one active `ADMIN` if this
 * identity were excluded?
 *
 * Returns a bare `bool` — no `Membership` / `User` / `Role[]` crosses the boundary. It is authoritative only
 * over administrators whose backing `User` both exists AND is `ACTIVE`: a membership orphaned from a
 * hard-deleted user must never keep a phantom administrator alive. The single-tenant organization is
 * implicit. The adapter (an `INNER JOIN membership ⋈ identity_user` filtering `roles=ADMIN AND status=ACTIVE`,
 * where the join itself excludes an orphaned membership) ships with the member-management slice that first
 * calls this port.
 */
interface ActiveAdministratorDirectory
{
    public function keepsAnActiveAdminWithout(string $userId): bool;
}
