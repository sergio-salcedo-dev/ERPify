<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Organization\Membership\Application;

use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Override;

/**
 * In-memory {@see MembershipRepository} that records saves, so a test can assert which membership a use
 * case grants and which user it belongs to.
 *
 * @internal
 */
final class InMemoryMembershipRepository implements MembershipRepository
{
    public bool $removeCalled = false;

    /** @var list<Membership> */
    public array $saved = [];

    #[Override]
    public function save(Membership $membership): void
    {
        $this->saved[] = $membership;
    }

    #[Override]
    public function remove(Membership $membership): void
    {
        $this->removeCalled = true;
    }

    #[Override]
    public function findByUserId(string $userId): ?Membership
    {
        foreach ($this->saved as $membership) {
            if ($membership->userId() === $userId) {
                return $membership;
            }
        }

        return null;
    }

    #[Override]
    public function findByOrganizationId(string $organizationId): array
    {
        return \array_values(
            \array_filter($this->saved, static fn (Membership $m): bool => $m->organizationId() === $organizationId),
        );
    }
}
