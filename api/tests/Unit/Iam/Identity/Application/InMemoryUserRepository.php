<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use Override;

/**
 * In-memory {@see UserRepository} that records every mutation, so a test can assert which user a use case
 * saves/removes. `findByEmail` compares canonical emails (both `User::email()` and the queried {@see Email}
 * are already lower-cased), mirroring the case-insensitive lookup the session user provider relies on.
 *
 * @internal
 */
final class InMemoryUserRepository implements UserRepository
{
    public bool $removeCalled = false;

    /** @var list<User> */
    public array $saved = [];

    /**
     * Ids the locked re-fetch was asked for — asserting on this list is how a single-threaded test proves
     * the use case takes the row lock at all (the harness cannot exercise the real race).
     *
     * @var list<string>
     */
    public array $forUpdateCalls = [];

    /** Simulates a user hard-deleted between the unlocked read and the locked re-fetch. */
    public bool $goneUnderLock = false;

    /** Runs at the locked re-fetch, so a test can commit a rival write at exactly the TOCTOU moment. */
    public ?Closure $onFindByIdForUpdate = null;

    /**
     * Set when a test is asserting WHERE this table's lock falls among the others. Both members below write
     * to it, because both take the row lock: the locked re-fetch explicitly, and the delete implicitly.
     */
    public ?LockOrderJournal $lockOrderJournal = null;

    public function __construct(private readonly ?User $preset = null)
    {
    }

    #[Override]
    public function save(User $user): void
    {
        $this->saved[] = $user;
    }

    #[Override]
    public function remove(User $user): void
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::IDENTITY_USER);

        $this->removeCalled = true;
    }

    #[Override]
    public function findById(string $id): ?User
    {
        return $this->preset;
    }

    #[Override]
    public function findByIdForUpdate(string $id): ?User
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::IDENTITY_USER);

        $this->forUpdateCalls[] = $id;

        if ($this->onFindByIdForUpdate instanceof Closure) {
            ($this->onFindByIdForUpdate)();
        }

        return $this->goneUnderLock ? null : $this->preset;
    }

    #[Override]
    public function findByEmail(Email $email): ?User
    {
        foreach ($this->all() as $user) {
            if ($user->email() === $email->toString()) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @return list<User>
     */
    private function all(): array
    {
        return $this->preset instanceof User ? [$this->preset, ...$this->saved] : $this->saved;
    }
}
