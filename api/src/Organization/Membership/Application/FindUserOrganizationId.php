<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Application;

use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Exception\MembershipCardinalityViolated;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Published read use case resolving the organization a user belongs to — the membership is the authoritative
 * user↔org link. It is the seam other contexts consume to learn a user's organization without
 * touching the {@see Membership} aggregate or its repository — session minting reads the admission-time
 * organization through this service, so the session context is never inferred from an "installation org".
 *
 * A user with no membership answers `null`, never an empty id and never an exception of this context: the
 * caller decides what an absent link means, in its own vocabulary, so a consumer across the boundary has no
 * reason to import this module's exceptions. A user with SEVERAL is not an answer at all and raises — see
 * {@see MembershipRepository::findByUserId()}.
 */
final readonly class FindUserOrganizationId
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    /**
     * @throws MembershipCardinalityViolated when the user resolves to more than one membership (500)
     *
     * @return string|null the organization id, or `null` when the user has no membership
     */
    public function of(string $userId): ?string
    {
        Uuid::ensure($userId);

        return $this->memberships->findByUserId($userId)?->organizationId();
    }
}
