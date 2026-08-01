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

    /**
     * Drops the row as well as recording the call. A double that only flipped the flag would let "the
     * membership is gone" pass against a store that still holds it — the assertion and the fact would have
     * nothing to do with each other.
     */
    #[Override]
    public function remove(Membership $membership): void
    {
        $this->removeCalled = true;
        $this->saved = \array_values(
            \array_filter($this->saved, static fn (Membership $m): bool => $m !== $membership),
        );
    }

    /**
     * Compares case-insensitively, like the column it stands in for: `membership.user_id` is a Postgres
     * `uuid`, so the real adapter matches one id spelled in either case. A `!==` here would make the double
     * STRICTER than production — and the divergence would be invisible, because no test can fail on it.
     */
    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        $remaining = \array_values(\array_filter(
            $this->saved,
            static fn (Membership $m): bool => 0 !== \strcasecmp($m->userId(), $userId),
        ));
        $deleted = \count($this->saved) - \count($remaining);
        $this->saved = $remaining;

        return $deleted;
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
