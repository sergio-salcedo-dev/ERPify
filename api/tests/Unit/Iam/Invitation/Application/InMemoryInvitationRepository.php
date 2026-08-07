<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Closure;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
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
     * Which read each flow took, by id. The two lookups return the same row, so nothing else in a unit test can
     * tell them apart — and the difference is the whole guarantee: an unlocked read lets a concurrent accept
     * commit between the load and the write, so a stale `SENT` passes the aggregate's own guard and the update
     * overwrites it. Recording the mode is what lets a test go red when a lock is dropped.
     *
     * @var list<string>
     */
    public array $lockedReads = [];

    /** @var list<string> */
    public array $unlockedReads = [];

    /**
     * userIds passed to the directed bulk delete. Recorded separately from the reads because the erasure
     * reaches this table by no other route, so "was this table touched at all" is a question only this
     * answers.
     *
     * @var list<string>
     */
    public array $deleteAllForInvitedUserCalls = [];

    /** Set when a test is asserting WHERE this table's lock falls among the others. */
    public ?LockOrderJournal $lockOrderJournal = null;

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
        $this->unlockedReads[] = $id;

        return $this->byId[\strtolower($id)] ?? null;
    }

    #[Override]
    public function findByIdForUpdate(string $id): ?Invitation
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::IAM_INVITATION);

        $this->lockedReads[] = $id;

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
        $this->lockOrderJournal?->locked(LockOrderJournal::IAM_INVITATION);

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
        $this->lockOrderJournal?->locked(LockOrderJournal::IAM_INVITATION);

        $this->deleteAllForInvitedUserCalls[] = $userId;
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
