<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Shared\Access\Domain\Role;
use Override;

/**
 * The authorization policy expressed as data, not code: three declarative maps resolve every decision, so
 * opening a resource to a role is a data edit, never a new branch in a resolver. A tripwire test asserts
 * the maps stay literal — see `StaticAuthorizationPolicyIsDataOnlyTest`.
 *
 * A permission is granted iff ANY holds:
 *   1. the subject is ADMIN — unconditional; ADMIN is never blocked by an opt-out;
 *   2. tier grant — the resource has NOT opted out of tiering AND the action is in some tier the subject holds;
 *   3. explicit grant — some role the subject holds is listed for that exact permission.
 *
 * The three maps are `const`: the language itself forbids closures/calls in a constant, so "data-only" is
 * compiler-enforced, not merely convention. They double as the constructor defaults — overriding them (a
 * test today, or a configurable store later, when "static" becomes "swap the store") needs no code change,
 * while production always runs the seed literals. `tierVerbs` carries the resource-agnostic ladder;
 * `explicitGrants` and `tierOptOut` hold the exceptions — a domain operation no tier verb covers (a
 * bank account's status change, granted to MANAGER; ADMIN needs no listing, it holds the wildcard) and
 * a sensitive resource that opts out of tiering (the audit trail, readable only via an explicit grant
 * so no generic tier auto-reads it).
 */
final readonly class StaticAuthorizationPolicy implements AuthorizationPolicy
{
    private const string WILDCARD = '*';

    /**
     * Role token -> the actions that tier may perform (`*` = every action). The privilege ladder.
     *
     * @var array<string, list<string>>
     */
    private const array TIER_VERBS = [
        Role::VIEWER->value => ['read'],
        Role::EDITOR->value => ['read', 'write'],
        Role::MANAGER->value => ['read', 'write', 'delete'],
        Role::ADMIN->value => [self::WILDCARD],
    ];

    /**
     * Permission string -> the role tokens explicitly granted it, independent of any tier.
     *
     * The five `users.*` grants localize the identity-console authorization surface as one data line.
     * `read`, `invite`, `changeStatus` and `changeRoles` back endpoints today; `erase` is listed ahead of the
     * GDPR use case that reaches it, so the console's full action vocabulary is declared in one place rather
     * than discovered per later story. It is a deliberate, documented seam — not dead code to cull. ADMIN
     * already passes through the unconditional clause, so these rows are functionally redundant for ADMIN; what
     * actually confines the console to ADMIN is `users` in {@see self::TIER_OPT_OUT} (without it, `read` — a
     * tier verb — would auto-grant to VIEWER).
     *
     * @var array<string, list<string>>
     */
    private const array EXPLICIT_GRANTS = [
        'auditTrail.read' => [Role::AUDIT_READER->value],
        'bankAccount.changeStatus' => [Role::MANAGER->value],
        'users.read' => [Role::ADMIN->value],
        'users.invite' => [Role::ADMIN->value],
        'users.changeStatus' => [Role::ADMIN->value],
        'users.changeRoles' => [Role::ADMIN->value],
        'users.erase' => [Role::ADMIN->value],
    ];

    /**
     * Resources that opt OUT of tier auto-grant: reachable only by an explicit grant (or by ADMIN).
     *
     * @var list<string>
     */
    private const array TIER_OPT_OUT = ['auditTrail', 'users'];

    /**
     * @param array<string, list<string>> $tierVerbs
     * @param array<string, list<string>> $explicitGrants
     * @param list<string>                $tierOptOut
     */
    public function __construct(
        private array $tierVerbs = self::TIER_VERBS,
        private array $explicitGrants = self::EXPLICIT_GRANTS,
        private array $tierOptOut = self::TIER_OPT_OUT,
    ) {
    }

    /**
     * @param list<string> $roles
     */
    #[Override]
    public function permits(Permission $permission, array $roles): bool
    {
        if ($this->grantedToAdmin($roles)) {
            return true;
        }

        if ($this->grantedByTier($permission, $roles)) {
            return true;
        }

        return $this->grantedExplicitly($permission, $roles);
    }

    /**
     * @param list<string> $roles
     */
    private function grantedToAdmin(array $roles): bool
    {
        return \in_array(Role::ADMIN->value, $roles, true);
    }

    /**
     * @param list<string> $roles
     */
    private function grantedByTier(Permission $permission, array $roles): bool
    {
        if (\in_array($permission->resource(), $this->tierOptOut, true)) {
            return false;
        }

        foreach ($roles as $role) {
            $verbs = $this->tierVerbs[$role] ?? [];

            if (\in_array(self::WILDCARD, $verbs, true) || \in_array($permission->action(), $verbs, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $roles
     */
    private function grantedExplicitly(Permission $permission, array $roles): bool
    {
        $grantedRoles = $this->explicitGrants[$permission->toString()] ?? [];

        return [] !== \array_intersect($grantedRoles, $roles);
    }
}
