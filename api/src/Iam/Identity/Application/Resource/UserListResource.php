<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Resource;

/**
 * Wire contract of one identity row in the register view (`GET /backoffice/users`). Plain data: the
 * serialized object is this DTO, never the {@see \Erpify\Iam\Identity\Domain\Entity\User} entity nor the
 * projection row, so the response shape is readable here without exposing the credential. `status` and
 * `roles` carry the enum identity `->value` on the wire (`"ACTIVE"`, `["ADMIN", "VIEWER"]`), never a
 * translated label; timestamps are pre-formatted ATOM strings (the mapper owns the format).
 *
 * It is kept SEPARATE from {@see UserDetailResource} even though the two shapes coincide today: the list
 * and detail views are independent wire contracts, and the detail one will host per-row action metadata
 * in a later story while the list stays lean. Do NOT fuse them into one shared DTO.
 */
final readonly class UserListResource
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
