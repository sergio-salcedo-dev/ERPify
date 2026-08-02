<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Override;

/**
 * In-memory {@see InvitationRepository} recording every save, so a use-case test can assert which invitation a
 * flow persists — and that none is written on a rejected accept. Kept local to the Invitation module's tests.
 *
 * @internal
 */
final class InMemoryInvitationRepository implements InvitationRepository
{
    /** @var list<Invitation> */
    public array $saved = [];

    /**
     * Keyed by the LOWER-CASED id, and read the same way. `iam_invitation.id` is a Postgres `uuid`, which
     * matches one id spelled in either case; a map keyed by the raw string would make the double stricter
     * than production on every lookup, and no test could fail on the difference.
     *
     * @var array<string, Invitation>
     */
    private array $byId = [];

    public function __construct(Invitation ...$preset)
    {
        foreach ($preset as $invitation) {
            $this->index($invitation);
        }
    }

    #[Override]
    public function save(Invitation $invitation): void
    {
        $this->saved[] = $invitation;
        $this->index($invitation);
    }

    #[Override]
    public function findById(string $id): ?Invitation
    {
        return $this->byId[\strtolower($id)] ?? null;
    }

    #[Override]
    public function findByIdForUpdate(string $id): ?Invitation
    {
        return $this->byId[\strtolower($id)] ?? null;
    }

    /**
     * Drops the rows from both views, so "the invitation is gone" is a fact about the store rather than a
     * flag the double sets about itself.
     */
    #[Override]
    public function deleteAllForInvitedUser(string $userId): int
    {
        $deleted = 0;

        foreach ($this->byId as $id => $invitation) {
            // Case-insensitive like the Postgres `uuid` column the real adapter matches on; a `!==` here
            // would make the double stricter than production, and nothing could ever fail on the difference.
            if (0 !== \strcasecmp($invitation->invitedUserId(), $userId)) {
                continue;
            }

            unset($this->byId[$id]);
            ++$deleted;
        }

        $this->saved = \array_values(\array_filter(
            $this->saved,
            static fn (Invitation $i): bool => 0 !== \strcasecmp($i->invitedUserId(), $userId),
        ));

        return $deleted;
    }

    private function index(Invitation $invitation): void
    {
        $id = $invitation->getId();

        if (null !== $id) {
            $this->byId[\strtolower($id)] = $invitation;
        }
    }
}
