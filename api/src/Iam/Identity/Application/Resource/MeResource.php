<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Resource;

/**
 * Wire contract of the "who am I" view (`GET /me`): the identity the PWA hydrates its session from. Carries the
 * real id, email and role names read off the authenticated token — never fabricated. Roles are the domain role
 * names (the `ROLE_` firewall prefix is stripped at the edge); no audit fields are exposed.
 */
final readonly class MeResource
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public array $roles,
    ) {
    }
}
