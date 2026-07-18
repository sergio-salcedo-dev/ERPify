<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

/**
 * Consumer-owned port answering the single question the status-change use case must ask before it takes an
 * identity out of the active set: would the organization still keep at least one active `ADMIN` if this
 * identity were excluded?
 *
 * Returns a bare `bool` — no `Membership` / `User` / `Role[]` crosses the boundary. It is authoritative only
 * over administrators whose backing `User` both exists AND is `ACTIVE`: an identity that is absent or no
 * longer `ACTIVE` must never keep a phantom administrator alive. The single-tenant organization is implicit.
 * Its production adapter reads the operational role source directly — a single-context `EXISTS` over
 * `identity_user` (`status = ACTIVE AND ADMIN ∈ roles AND id <> excluded`), which owns roles today —
 * and ships with the status-change slice that first calls this port; it re-points to `Membership` only if
 * tenancy ever moves the authoritative source.
 */
interface ActiveAdministratorDirectory
{
    public function keepsAnActiveAdminWithout(string $userId): bool;
}
