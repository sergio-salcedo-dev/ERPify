<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Security;

/**
 * The `bankAccount` permission vocabulary this module gates its routes with, consumed by
 * `#[IsGranted(BankAccountPermission::READ)]` on the BankAccount controllers and resolved by the shared
 * `PermissionVoter` against the tier policy.
 *
 * Colocated in the module's edge so `BankAccount` owns its own `(resource, action)` strings: a resource
 * is a governable business object, not a route, so the same `read` guards both the flat collection and
 * the nested `/banks/{id}/accounts`. The constant — rather than a hand-typed literal repeated across
 * eight controllers — makes each call site compiler-checked: a case-sensitive permission string typo'd
 * inline fails only at request time as a silent over-restriction, never at build time.
 *
 * `read`/`write`/`delete` are auto-granted by the role tiers; `changeStatus` is a domain operation, not
 * a CRUD verb, so it is never tiered — it is reachable only through an explicit policy grant.
 *
 * A constants class, not a backed enum: the value type is already the `Permission` VO, and this string
 * is consumed verbatim by `#[IsGranted]`, `PermissionVoter::supports()`, and `Permission::fromString()`
 * — never held, matched, or iterated as a type. See the D5 carve-out in
 * docs/adr/rbac-authorization-model.md.
 */
final class BankAccountPermission
{
    public const string READ = 'bankAccount.read';

    public const string WRITE = 'bankAccount.write';

    public const string DELETE = 'bankAccount.delete';

    public const string CHANGE_STATUS = 'bankAccount.changeStatus';
}
