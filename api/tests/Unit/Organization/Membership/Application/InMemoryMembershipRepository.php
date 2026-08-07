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

    /**
     * userIds passed to the directed bulk delete. Recorded rather than inferred from the store, because "the
     * statement never ran" and "it ran and matched nothing" are the same empty store and a different claim.
     *
     * @var list<string>
     */
    public array $deleteAllForUserCalls = [];

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
     * Every lookup below compares case-insensitively, like the columns they stand in for: `membership.user_id`
     * and `membership.organization_id` are Postgres `uuid`, so the real adapter matches one id spelled in
     * either case. A `!==` would make the double STRICTER than production — and the divergence would be
     * invisible, because no test can fail on it. Applying it to the delete alone would leave the reads free
     * to disagree with the delete about which rows exist.
     */
    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        $this->deleteAllForUserCalls[] = $userId;

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
            if (0 === \strcasecmp($membership->userId(), $userId)) {
                return $membership;
            }
        }

        return null;
    }

    #[Override]
    public function findByOrganizationId(string $organizationId): array
    {
        return \array_values(\array_filter(
            $this->saved,
            static fn (Membership $m): bool => 0 === \strcasecmp($m->organizationId(), $organizationId),
        ));
    }
}
