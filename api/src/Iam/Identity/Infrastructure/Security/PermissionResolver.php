<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

/**
 * Expands bare role tokens into the set of permissions they hold, by asking {@see AuthorizationPolicy} about
 * every permission {@see PermissionCatalog} publishes. This is the only place the two halves of the
 * authorization plane meet: the policy stays a closed one-question port, the catalog stays inert data, and
 * the enumeration lives here rather than inside either.
 *
 * The result is a **derived read-model**, recomputed per request from roles — permissions are never stored,
 * and no aggregate carries them.
 */
final readonly class PermissionResolver
{
    public function __construct(
        private PermissionCatalog $catalog,
        private AuthorizationPolicy $policy,
    ) {
    }

    /**
     * @param list<string> $bareRoles role names without the firewall's `ROLE_` prefix, as the policy expects
     *
     * @return list<string>
     */
    public function forRoles(array $bareRoles): array
    {
        $held = [];

        foreach ($this->catalog->all() as $permission) {
            if ($this->policy->permits($permission, $bareRoles)) {
                $held[] = $permission->toString();
            }
        }

        return $held;
    }
}
