<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use Override;

/**
 * In-memory {@see RecoverySecretRepository} that records every mutation, so a use-case test can assert what a
 * case persists, retires and sweeps.
 *
 * The two `ForUpdate` finders journal their acquisition and the plain ones do not, which is the double
 * standing in for the invariant its port declares: the resolving lookup takes no lock, and only a locked
 * re-read may carry a decision. A double that journalled both would report an order the production adapter
 * does not take, and one that journalled neither would leave the whole lock-order claim unmeasurable.
 *
 * @internal
 */
final class InMemoryRecoverySecretRepository implements RecoverySecretRepository
{
    /** @var list<RecoverySecret> */
    public array $saved = [];

    /** @var list<RecoverySecret> */
    public array $removed = [];

    /** @var list<string> userIds passed to deleteAllForUser */
    public array $deleteAllForUserCalls = [];

    /** Set when a test is asserting WHERE this table's lock falls among the others. */
    public ?LockOrderJournal $lockOrderJournal = null;

    /**
     * Runs at the LOCKED re-read, so a test can commit a rival write at exactly the TOCTOU moment — the
     * only instant at which a second redemption or a revocation can change what this one decides.
     */
    public ?Closure $onLockedRead = null;

    /** Makes the retire fail the way a flush fault would, after the session has already been minted. */
    public ?Closure $onRemove = null;

    /** @var array<string, RecoverySecret> */
    private array $bySelector = [];

    public function __construct(RecoverySecret ...$preset)
    {
        foreach ($preset as $secret) {
            $this->index($secret);
        }
    }

    #[Override]
    public function save(RecoverySecret $secret): void
    {
        $this->saved[] = $secret;
        $this->index($secret);
    }

    #[Override]
    public function remove(RecoverySecret $secret): void
    {
        if ($this->onRemove instanceof Closure) {
            ($this->onRemove)();
        }

        $this->removed[] = $secret;

        $selector = $secret->getId();

        if (null !== $selector) {
            unset($this->bySelector[$selector]);
        }
    }

    #[Override]
    public function findBySelector(string $selector): ?RecoverySecret
    {
        return $this->bySelector[$selector] ?? null;
    }

    #[Override]
    public function findBySelectorForUpdate(string $selector): ?RecoverySecret
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::RECOVERY_SECRET);

        if ($this->onLockedRead instanceof Closure) {
            ($this->onLockedRead)();
        }

        return $this->bySelector[$selector] ?? null;
    }

    #[Override]
    public function findByUserId(string $userId): ?RecoverySecret
    {
        return $this->firstFor($userId);
    }

    #[Override]
    public function findByUserIdForUpdate(string $userId): ?RecoverySecret
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::RECOVERY_SECRET);

        if ($this->onLockedRead instanceof Closure) {
            ($this->onLockedRead)();
        }

        return $this->firstFor($userId);
    }

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::RECOVERY_SECRET);

        $this->deleteAllForUserCalls[] = $userId;
        $deleted = 0;

        foreach ($this->bySelector as $key => $secret) {
            // Case-insensitive like the Postgres `uuid` column the real adapter matches on; a `!==` here
            // would make the double STRICTER than production, and no test could fail on the difference.
            if (0 === \strcasecmp($secret->userId(), $userId)) {
                unset($this->bySelector[$key]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function firstFor(string $userId): ?RecoverySecret
    {
        foreach ($this->bySelector as $secret) {
            if (0 === \strcasecmp($secret->userId(), $userId)) {
                return $secret;
            }
        }

        return null;
    }

    private function index(RecoverySecret $secret): void
    {
        $selector = $secret->getId();

        if (null !== $selector) {
            $this->bySelector[$selector] = $secret;
        }
    }
}
