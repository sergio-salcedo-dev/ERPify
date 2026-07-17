<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

/**
 * Every permission the installation publishes — the enumerable counterpart to {@see AuthorizationPolicy},
 * which answers "may these roles do X?" for one permission at a time and deliberately cannot enumerate.
 * Anything that needs the *set* rather than a verdict (the `/me` authorization read-model) walks this list
 * and filters it through the policy; see {@see PermissionResolver}.
 *
 * Central, and holding other modules' strings, on purpose. A per-module provider would have to implement an
 * interface declared here and hand back {@see Permission} objects — types, therefore a compile-time import
 * from `Backoffice/*` into `Iam`, which the bounded-context gates reject and which no allowlist entry would
 * legitimately cover. Strings are not dependencies: this is the shape {@see StaticAuthorizationPolicy} already
 * has, listing `auditTrail.read` and `bankAccount.changeStatus` without depending on either module. The
 * catalog belongs to the authorization plane and moves with it if that plane is extracted to a shared kernel.
 *
 * Data, never mechanism — pinned by a tokenising tripwire, exactly like the policy's own maps. The price of
 * centralising is that a new resource now costs one line here where it used to cost nothing; the completeness
 * gate ({@see \Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security\PermissionCatalogCoversEveryGatedRouteTest})
 * is what makes that price safe, by turning a forgotten entry into a failed build rather than a client-side
 * gate that silently denies.
 */
final readonly class PermissionCatalog
{
    /**
     * `users.invite`, `users.changeStatus` and `users.erase` have no endpoint yet: they are declared ahead of
     * the use cases that will reach them, matching the seam {@see StaticAuthorizationPolicy} documents. The
     * completeness gate asserts the catalog is a superset of what is gated, never an equality, so they pass.
     *
     * @var list<string>
     */
    private const array PERMISSIONS = [
        'auditTrail.read',
        'bank.read',
        'bank.write',
        'bank.delete',
        'bankAccount.read',
        'bankAccount.write',
        'bankAccount.delete',
        'bankAccount.changeStatus',
        'users.read',
        'users.invite',
        'users.changeStatus',
        'users.erase',
    ];

    /**
     * @throws InvalidPermission if a catalog entry is not a well-formed `<resource>.<action>`; a typo here is
     *                           a bug, kept out by the catalog's own well-formedness test
     *
     * @return list<Permission>
     */
    public function all(): array
    {
        return \array_map(Permission::fromString(...), self::PERMISSIONS);
    }

    /**
     * Whether $candidate is a permission this installation publishes. Takes a raw string because its caller
     * (the completeness gate) reads unvalidated `#[IsGranted]` arguments.
     */
    public function contains(string $candidate): bool
    {
        return \in_array($candidate, self::PERMISSIONS, true);
    }
}
