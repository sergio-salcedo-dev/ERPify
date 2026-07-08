<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Security;

/**
 * The `bank` permission vocabulary this module gates its routes with, consumed by
 * `#[IsGranted(BankPermission::READ)]` on the Bank controllers and resolved by the shared
 * `PermissionVoter` against the tier policy.
 *
 * Colocated in the module's edge so `Bank` owns its own `(resource, action)` strings (a resource is a
 * governable business object, not a route). The constant — rather than a hand-typed literal repeated
 * across seven controllers — makes each call site compiler-checked: a case-sensitive permission string
 * typo'd inline fails only at request time as a silent over-restriction, never at build time.
 *
 * There is no `close`: a bank is never closed, only deleted — the hard delete (`DELETE /banks/{id}`) is its
 * terminal operation. `read`/`write`/`delete` are auto-granted by the role tiers, so gating `Bank` adds no
 * row to the authorization policy.
 */
final class BankPermission
{
    public const string READ = 'bank.read';

    public const string WRITE = 'bank.write';

    public const string DELETE = 'bank.delete';
}
