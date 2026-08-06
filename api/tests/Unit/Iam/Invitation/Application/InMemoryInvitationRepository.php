<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Closure;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
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
     * Invoked before each {@see save()} with the invitation about to be written, so a test can make the n-th
     * write of a multi-row revocation fail and observe that the whole operation is undone. The hook sits on
     * the store rather than on the aggregate because that is where a real failure lives — a constraint or a
     * lost connection, not a refused domain transition.
     *
     * @var ?Closure(Invitation): void
     */
    public ?Closure $onSave = null;

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
        if ($this->onSave instanceof Closure) {
            ($this->onSave)($invitation);
        }

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
     * Filters the same store the production adapter queries, so "which invitations are revocable" is decided
     * by the rows' own status and invitee — never by an expectation the test seeds separately. The lock the
     * port promises has no in-memory analogue; what the double can and does preserve is the SET the caller
     * gets, which is what its all-or-nothing behaviour is asserted over.
     *
     * @return list<Invitation>
     */
    #[Override]
    public function findSentByInvitedUserForUpdate(string $userId): array
    {
        return \array_values(\array_filter(
            $this->byId,
            static fn (Invitation $invitation): bool => InvitationStatus::SENT === $invitation->status()
                && 0 === \strcasecmp($invitation->invitedUserId(), $userId),
        ));
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
