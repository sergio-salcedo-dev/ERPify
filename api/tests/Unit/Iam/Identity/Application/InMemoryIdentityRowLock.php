<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Application\IdentityRowLock;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use Override;

/**
 * In-memory {@see IdentityRowLock}: runs the operation inline, and records what a single-threaded test can say
 * about a lock — that it was asked for, in which order against the other tables, and whether a write happened
 * while it was held ({@see $holding}, read by {@see RowLockAwareAuditLogger}).
 *
 * Existence is answered by the repository it is given, so an identity a test never seeded is absent under the
 * lock exactly as it is to every other reader; with no repository every id is present and no address resolves,
 * which is what a test asserting only the write needs. That the lock really makes a rival erasure wait is not a
 * claim this double can carry: Postgres's, in
 * {@see \Erpify\Tests\Functional\Iam\Identity\LateAuditWriterErasureSerialisationFunctionalTest}.
 *
 * @internal
 */
final class InMemoryIdentityRowLock implements IdentityRowLock
{
    /**
     * Each lock asked for, keyed by what named it — the id, or the canonical address.
     *
     * @var list<string>
     */
    public array $lockRequests = [];

    /** The transactions this lock opened, which are its own and never the caller's. */
    public int $transactionsOpened = 0;

    /** True only while an operation runs under the lock. */
    public bool $holding = false;

    /** Simulates an identity erased before the lock was granted. */
    public bool $goneUnderLock = false;

    /** Runs as the lock is taken, before the operation — a test can throw from it to fail the lock itself. */
    public ?Closure $onLock = null;

    public ?LockOrderJournal $lockOrderJournal = null;

    public function __construct(private readonly ?UserRepository $users = null)
    {
    }

    #[Override]
    public function whileHeld(string $userId, callable $operation): bool
    {
        return $this->inTransaction($userId, function () use ($userId, $operation): bool {
            if (!$this->exists($userId)) {
                return false;
            }

            $operation();

            return true;
        });
    }

    #[Override]
    public function whileHeldByEmail(Email $email, callable $operation): void
    {
        $this->inTransaction($email->toString(), function () use ($email, $operation): bool {
            $user = $this->goneUnderLock ? null : $this->users?->findByEmail($email);
            $operation($user instanceof User ? $user->getId() : null);

            return true;
        });
    }

    /**
     * @param callable(): bool $body
     */
    private function inTransaction(string $key, callable $body): bool
    {
        ++$this->transactionsOpened;
        $this->lockRequests[] = $key;
        $this->lockOrderJournal?->locked(LockOrderJournal::IDENTITY_USER);

        if ($this->onLock instanceof Closure) {
            ($this->onLock)();
        }

        $this->holding = true;

        try {
            return $body();
        } finally {
            $this->holding = false;
        }
    }

    private function exists(string $userId): bool
    {
        if ($this->goneUnderLock) {
            return false;
        }

        return !$this->users instanceof UserRepository || $this->users->findById($userId) instanceof User;
    }
}
