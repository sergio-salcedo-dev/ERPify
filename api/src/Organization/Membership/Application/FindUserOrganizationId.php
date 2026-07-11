<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Application;

use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Exception\MembershipNotFound;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Published read use case resolving the organization a user belongs to — the membership is the authoritative
 * user↔org link. It is the seam other contexts consume to learn a user's organization without
 * touching the {@see Membership} aggregate or its repository — session minting reads the admission-time
 * organization through this service, so the session context is never inferred from an "installation org".
 *
 * A user with no membership is a {@see MembershipNotFound} (never an empty id): the caller decides the meaning
 * — the minting path treats it as fail-closed.
 */
final readonly class FindUserOrganizationId
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    /**
     * @throws MembershipNotFound when the user has no membership
     */
    public function of(string $userId): string
    {
        Uuid::ensure($userId);

        $membership = $this->memberships->findByUserId($userId) ?? throw MembershipNotFound::forUser($userId);

        return $membership->organizationId();
    }
}
