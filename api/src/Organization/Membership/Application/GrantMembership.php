<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Application;

use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Exception\OrganizationNotProvisioned;
use Erpify\Organization\Membership\Domain\Exception\UserAlreadyMember;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Organization\Organization\Domain\Repository\OrganizationRepository;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Binds a user to the installation's organization — the published entry point every user-onboarding path
 * (bootstrap today, invitation later) funnels through so a user never exists without exactly one membership.
 * It resolves the single organization itself, so callers in other contexts stay unaware of the organization
 * aggregate and only depend on this one service.
 */
final readonly class GrantMembership
{
    public function __construct(
        private MembershipRepository $memberships,
        private OrganizationRepository $organizations,
    ) {
    }

    /**
     * @throws InvalidUuidException       when the user id is not a well-formed UUID
     * @throws OrganizationNotProvisioned when no organization has been provisioned yet
     * @throws UserAlreadyMember          when the user already belongs to the organization
     */
    public function grant(string $userId): Membership
    {
        Uuid::ensure($userId);

        $organization = $this->organizations->findTheOne() ?? throw new OrganizationNotProvisioned();
        $organizationId = $organization->getId() ?? throw new OrganizationNotProvisioned();

        if ($this->memberships->findByUserId($userId) instanceof Membership) {
            throw new UserAlreadyMember($userId);
        }

        $membership = Membership::grant(Uuid::generate(), $userId, $organizationId);

        $this->memberships->save($membership);

        return $membership;
    }
}
