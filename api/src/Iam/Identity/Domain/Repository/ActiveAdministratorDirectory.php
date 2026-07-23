<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

/**
 * Consumer-owned port over the organization's administrators, answering the two questions the write-side use
 * cases must ask before they change one: would at least one active `ADMIN` remain if this identity were
 * excluded, and does this identity carry the role at all?
 *
 * Both return a bare `bool` — no `Membership` / `User` / `Role[]` crosses the boundary. The single-tenant
 * organization is implicit. The production adapter reads the operational role source directly — a
 * single-context read over `identity_user`, which owns roles today — and re-points to `Membership` only if
 * tenancy ever moves the authoritative source.
 */
interface ActiveAdministratorDirectory
{
    /**
     * Authoritative only over administrators whose backing `User` both exists AND is `ACTIVE`: an identity
     * that is absent or no longer `ACTIVE` must never keep a phantom administrator alive. Its adapter takes a
     * `FOR UPDATE` lock over the active-admin set so concurrent transitions serialize — the invariant is
     * set-based, so it must be read inside the caller's transaction.
     */
    public function keepsAnActiveAdminWithout(string $userId): bool;

    /**
     * Whether this identity carries `ADMIN`, regardless of its status — a suspended administrator still holds
     * the role. A per-subject precondition rather than a set invariant, so it needs no lock: losing the race
     * against a concurrent role change costs at most an interleaving in which both operations are audited.
     */
    public function holdsAdministratorRole(string $userId): bool;
}
