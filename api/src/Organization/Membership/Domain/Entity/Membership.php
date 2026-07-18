<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The authoritative user↔organization link: it binds an identity to the organization it belongs to and
 * carries the org-scoped roles that identity holds. A user is never "global" — exactly one membership per
 * user (the UNIQUE `user_id`), assigned before the user is ever active. Roles live here, not on the user:
 * they are a property of belonging to an organization, so the org-scoping seam and the authorization data
 * share one home.
 *
 * Cross-module references are by id, never a mapped association: `userId` points at an `Iam\Identity\User`
 * and `organizationId` at an {@see \Erpify\Organization\Organization\Domain\Entity\Organization}; the
 * physical foreign keys are re-injected schema-aware by a `postGenerateSchema` listener so no object graph
 * crosses a module boundary. {@see Role} is consumed as published authorization vocabulary.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
#[ORM\Index(name: 'idx_membership_organization_id', columns: ['organization_id'])]
final class Membership extends AggregateRoot
{
    #[ORM\Column(name: 'user_id', type: Types::GUID, unique: true)]
    private string $userId;

    #[ORM\Column(name: 'organization_id', type: Types::GUID)]
    private string $organizationId;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles;

    private function __construct(string $id, string $userId, string $organizationId, Role ...$roles)
    {
        parent::__construct();

        Uuid::ensure($userId);
        Uuid::ensure($organizationId);

        $this->id = $id;
        $this->userId = $userId;
        $this->organizationId = $organizationId;
        $this->roles = $this->distinctRoleValues($roles);
    }

    public static function grant(string $id, string $userId, string $organizationId, Role ...$roles): self
    {
        return new self($id, $userId, $organizationId, ...$roles);
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return \array_map(Role::from(...), $this->roles);
    }

    public function hasRole(Role $role): bool
    {
        return \in_array($role->value, $this->roles, true);
    }

    /**
     * @param array<Role> $roles
     *
     * @return list<string>
     */
    private function distinctRoleValues(array $roles): array
    {
        $values = [];

        foreach ($roles as $role) {
            $values[$role->value] = $role->value;
        }

        return \array_values($values);
    }
}
