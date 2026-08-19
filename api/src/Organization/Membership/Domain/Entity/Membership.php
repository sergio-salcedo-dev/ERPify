<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The authoritative user↔organization link: it binds an identity to the organization it belongs to. A user is
 * never "global" — exactly one membership per user (the UNIQUE `user_id`), assigned before the user is ever
 * active.
 *
 * It carries no roles. Authorization is answered from the identity aggregate alone (`identity_user.roles`), so
 * there is exactly one role authority and no second source that can disagree with it about what a person may
 * do — a divergence nothing in the schema could have detected, since the two live in different bounded
 * contexts and no constraint spans them. Genuinely organization-scoped authority (ownership, per-tenant roles)
 * would reopen that choice deliberately rather than by accretion; see `docs/adr/authorization-model-boundaries.md`.
 *
 * Cross-module references are by id, never a mapped association: `userId` points at an `Iam\Identity\User`
 * and `organizationId` at an {@see \Erpify\Organization\Organization\Domain\Entity\Organization}; the
 * physical foreign keys are re-injected schema-aware by a `postGenerateSchema` listener so no object graph
 * crosses a module boundary.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
#[ORM\Index(name: 'idx_membership_organization_id', columns: ['organization_id'])]
final class Membership extends AggregateRoot
{
    #[ORM\Column(name: 'user_id', type: Types::GUID, unique: true)]
    #[PersonSubjectReference(erasedBy: 'src/Organization/Membership/Application/PurgeUserMembership.php')]
    private string $userId;

    #[ORM\Column(name: 'organization_id', type: Types::GUID)]
    private string $organizationId;

    private function __construct(string $id, string $userId, string $organizationId)
    {
        parent::__construct();

        Uuid::ensure($userId);
        Uuid::ensure($organizationId);

        $this->id = $id;
        $this->userId = $userId;
        $this->organizationId = $organizationId;
    }

    public static function grant(string $id, string $userId, string $organizationId): self
    {
        return new self($id, $userId, $organizationId);
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
