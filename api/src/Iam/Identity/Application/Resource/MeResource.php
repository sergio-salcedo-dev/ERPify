<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Resource;

/**
 * Wire contract of the "who am I" view (`GET /me`): the identity the PWA hydrates its session from. Carries the
 * real id, email and role names read off the authenticated token — never fabricated. Roles are the domain role
 * names (the `ROLE_` firewall prefix is stripped at the edge); no audit fields are exposed.
 *
 * Permissions accompany the roles so the client can gate its own surfaces. They are a **derived** set — the
 * roles expanded through the authorization policy at the edge, not a stored attribute of the identity — and
 * they are a convenience for rendering only: every route enforces its own `#[IsGranted]` server-side, so a
 * client that ignores or forges this field gains nothing.
 */
final readonly class MeResource
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions the set the roles grant, derived per request and never stored
     */
    public function __construct(
        public string $id,
        public string $email,
        public array $roles,
        public array $permissions,
    ) {
    }
}
