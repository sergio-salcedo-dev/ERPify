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
 * A permission is granted iff EITHER holds:
 *   1. tier grant — the resource has NOT opted out of tiering AND the action is in some tier the subject holds;
 *   2. explicit grant — some role the subject holds is listed for that exact permission.
 *
 * ADMIN is not a superuser clause, it is a tier: `ADMIN => ['*']` in {@see self::TIER_VERBS} reaches every
 * action of every resource that tiers at all, so a brand-new resource still costs zero policy rows. What it
 * deliberately does NOT do is bypass {@see self::TIER_OPT_OUT}. That is the whole point: opting a resource out
 * of tiering is how a governance, compliance or separation-of-duties surface becomes fail-CLOSED for everyone,
 * ADMIN included, and is then reopened one declared row at a time. Without it, every capability invented in the
 * future would be granted to ADMIN silently, by a default nobody chose.
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
     * The `users.*` grants localize the identity-console authorization surface as one data line each, all
     * ADMIN-only. They are the single-place, declared record of each console action's intended grantee
     * (load-bearing the day a non-ADMIN role earns one), and — because `users` opts out of tiering — they are
     * also what actually grants ADMIN those actions. The opt-out is what confines the console to this list at
     * all (without it, `read` — a tier verb — would auto-grant to VIEWER).
     *
     * `users.grantAdmin` is the one that governs a payload rather than an endpoint: it names the act of handing
     * out `ADMIN` itself, checked by the invite and role-change controllers only when the submitted set carries
     * that role. Its row starts at ADMIN and cannot start empty — `users.invite` and `users.changeRoles` are
     * already ADMIN-only, so withholding it from ADMIN would make a second administrator impossible to create,
     * which in turn strands the erasure of the sole administrator (that erasure is refused while the subject
     * still holds `ADMIN`, and the demotion needs another administrator to survive it). Tightening it later —
     * to a dedicated delegation role, say — is an edit to this one line.
     *
     * `auditTrail.read` lists ADMIN beside AUDIT_READER because `auditTrail` also opts out of tiering, so the
     * row is what grants it. That an administrator may read the trail auditing them is a decided
     * separation-of-duties position, not a default — `docs/adr/authorization-model-boundaries.md` D3 carries
     * the justification and the trigger that reopens it (a customer requiring contractual separation of
     * duties). It is a declared row rather than something left to fall out of how the policy resolves.
     *
     * @var array<string, list<string>>
     */
    private const array EXPLICIT_GRANTS = [
        'auditTrail.read' => [Role::AUDIT_READER->value, Role::ADMIN->value],
        'bankAccount.changeStatus' => [Role::MANAGER->value],
        'users.read' => [Role::ADMIN->value],
        'users.invite' => [Role::ADMIN->value],
        'users.revokeInvitation' => [Role::ADMIN->value],
        'users.changeStatus' => [Role::ADMIN->value],
        'users.changeRoles' => [Role::ADMIN->value],
        'users.grantAdmin' => [Role::ADMIN->value],
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
        if ($this->grantedByTier($permission, $roles)) {
            return true;
        }

        return $this->grantedExplicitly($permission, $roles);
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
