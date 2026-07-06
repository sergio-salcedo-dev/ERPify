<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Security;

/**
 * Decides whether a set of roles is permitted a {@see Permission}.
 *
 * Deliberately neutral: it speaks only a permission, bare role tokens (the domain `Role->value`s, with no
 * Symfony `ROLE_` prefix) and a boolean — never {@see \Erpify\Backoffice\Identity\Domain\Enum\Role},
 * `User` or `SecurityUser`. That neutrality is what lets the whole authorization core be promoted to a
 * shared kernel later, and lets a caller in any bounded context ask "may these roles do this?" without
 * depending on Identity's aggregate or on the framework.
 */
interface AuthorizationPolicy
{
    /**
     * @param list<string> $roles bare role tokens (no `ROLE_` prefix)
     */
    public function permits(Permission $permission, array $roles): bool;
}
