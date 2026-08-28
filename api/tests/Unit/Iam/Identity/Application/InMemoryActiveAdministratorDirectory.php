<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use Override;

/**
 * In-memory {@see ActiveAdministratorDirectory} that IS the executable spec of the boolean question the
 * production adapter answers with a single-context containment read over `identity_user`
 * (`status=ACTIVE AND ADMIN ∈ roles`); the adapter additionally takes a `FOR UPDATE` row lock to serialize
 * concurrent transitions, out of scope for this in-memory spec. Each entry is an admin identity keyed by its
 * user id and valued by whether that user exists AND is `ACTIVE`. A phantom admin (whose backing user was
 * hard-deleted or is no longer `ACTIVE`) is present with `false` and deliberately never counts, so it can
 * never keep the last real administrator suspendable.
 *
 * Records the ids it was asked about, so a test can assert not only the verdict but whether the question was
 * put at all — the observable difference between a guard a use case always evaluates and one it evaluates only
 * when the change actually threatens the invariant.
 *
 * @internal
 */
final class InMemoryActiveAdministratorDirectory implements ActiveAdministratorDirectory
{
    /** @var list<string> */
    public array $askedWithout = [];

    /** @var list<string> */
    public array $askedWhetherAdministrator = [];

    public int $setLocksTaken = 0;

    /**
     * How many single-row locks had already been taken each time the SET lock was. The order is the whole
     * invariant and it is the half a call-count assertion cannot see: a set lock taken after the row lock
     * orders nothing that has not already happened, and leaves the ABBA it exists against reachable.
     *
     * @var list<int>
     */
    public array $rowLocksTakenBeforeEachSetLock = [];

    /**
     * Set when a test is asserting where this lock falls among OTHER tables'. The set lock is over
     * `identity_user` rows, so it belongs in that sequence like any other acquisition on that table — and a
     * caller that takes it without meaning to reorder anything is exactly how a cross-table order is
     * reopened: the statement is about administrators, but the lock is about the table.
     */
    public ?LockOrderJournal $lockOrderJournal = null;

    /**
     * @param array<string, bool>   $adminUserIsActive admin user id => (its backing User exists AND is ACTIVE)
     * @param (Closure(): int)|null $rowLockCounter    single-row locks taken so far; omit when a test only
     *                                                 cares that the set lock was taken at all
     */
    public function __construct(
        private readonly array $adminUserIsActive,
        private readonly ?Closure $rowLockCounter = null,
    ) {
    }

    #[Override]
    public function lockActiveAdministrators(): void
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::IDENTITY_USER);

        ++$this->setLocksTaken;

        if ($this->rowLockCounter instanceof Closure) {
            $this->rowLocksTakenBeforeEachSetLock[] = ($this->rowLockCounter)();
        }
    }

    #[Override]
    public function keepsAnActiveAdminWithout(string $userId): bool
    {
        $this->askedWithout[] = $userId;

        $anotherActiveAdminRemains = \array_any(
            $this->adminUserIsActive,
            static fn (bool $isActive, $adminUserId): bool => $isActive
                && 0 !== \strcasecmp((string) $adminUserId, $userId),
        );

        // The second half of the adapter's reading, and it must mirror it or the double lies about the one
        // state the two callers disagree on: an identity the active-admin set never held is removed from
        // nothing, so it cannot drain a set of any size, the empty one included. An entry valued `false` is a
        // phantom — the identity carries ADMIN without being ACTIVE — and is no more a member than an absent
        // key is.
        return $anotherActiveAdminRemains || !($this->activeEntryFor($userId) ?? false);
    }

    /**
     * Identity comparison is case-insensitive, mirroring the adapter's `strcasecmp` in both of its halves.
     * `Uuid::ensure()` validates a route id without normalising it and `Symfony\Component\Uid\Uuid::isValid`
     * admits either casing, so an upper-case id reaches the guard from the wire. A case-sensitive double
     * answers `true` (permit) where the adapter answers `false` (409) for the same input, which makes every
     * unit test that uses it a false green on the one axis the adapter's own test declares important.
     */
    private function activeEntryFor(string $userId): ?bool
    {
        foreach ($this->adminUserIsActive as $adminUserId => $isActive) {
            if (0 === \strcasecmp((string) $adminUserId, $userId)) {
                return $isActive;
            }
        }

        return null;
    }

    /**
     * Membership of the map IS carrying the role: an entry valued `false` is an administrator whose backing
     * user is absent or no longer `ACTIVE`, and that identity still holds `ADMIN`. Which is exactly why the
     * production adapter's second query drops the status predicate the first one applies.
     */
    #[Override]
    public function holdsAdministratorRole(string $userId): bool
    {
        $this->askedWhetherAdministrator[] = $userId;

        return null !== $this->activeEntryFor($userId);
    }

    /**
     * Same verdict as the unlocked reading — in memory there is no snapshot to be stale against — recorded
     * separately and journalled as a row lock on `identity_user`, because what a caller must get right about
     * this member is not the answer but WHERE it asks: the lock lands on that table, so taking it before the
     * invitations inverts an order the accept path cannot reverse.
     */
    #[Override]
    public function holdsAdministratorRoleForUpdate(string $userId): bool
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::IDENTITY_USER);

        return \array_key_exists($userId, $this->adminUserIsActive);
    }
}
