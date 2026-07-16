<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Resource;

/**
 * Wire contract of the single-identity detail view (`GET /backoffice/users/{id}`). Plain data: the
 * serialized object is this DTO, never the {@see \Erpify\Iam\Identity\Domain\Entity\User} entity — so the
 * credential, the failed-attempt counter and the lockout timestamp can never reach the wire. There is
 * deliberately NO `permissions` section: the roles ARE the authorization map, and no `User.permissions`
 * exists to serialize. `status` / `roles` carry the enum `->value`; timestamps are ATOM strings.
 *
 * It is kept SEPARATE from {@see UserListResource} even though the two shapes coincide today (see that
 * class): the detail view will diverge — hosting per-row action metadata — in a later story. Do NOT fuse.
 */
final readonly class UserDetailResource
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $status,
        public array $roles,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
