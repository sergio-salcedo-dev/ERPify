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
     * Users the store already holds, for the cases the single `$preset` cannot express: a sweep over several
     * rows needs each id to resolve to its own aggregate, and it asserts on `$saved`, so the starting
     * population cannot be staged there.
     *
     * @var list<User>
     */
    public array $seeded = [];

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

    /** The same hook on the address-keyed locked re-fetch, which the failed-login path takes. */
    public ?Closure $onFindByEmailForUpdate = null;

    /**
     * Runs before the user is recorded, so a test can make one row's persistence fail the way a flush fault
     * would — the failure a sweep's per-row boundary exists to absorb, and one no mailer double can stage.
     */
    public ?Closure $onSave = null;

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
        if ($this->onSave instanceof Closure) {
            ($this->onSave)($user);
        }

        $this->saved[] = $user;
    }

    #[Override]
    public function remove(User $user): void
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::IDENTITY_USER);

        $this->removeCalled = true;
    }

    /**
     * Keyed by id, like the Doctrine repository it stands in for. A double that answered the preset to any id
     * would make every multi-row test vacuous: a sweep asking for three distinct ids would be handed the same
     * aggregate three times and still look green.
     */
    #[Override]
    public function findById(string $id): ?User
    {
        foreach ($this->all() as $user) {
            if ($user->getId() === $id) {
                return $user;
            }
        }

        return null;
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
        return $this->byEmail($email);
    }

    /**
     * Journals its acquisition and honours {@see $goneUnderLock}, exactly as the id-keyed locked finder does:
     * the failed-login path resolves its identity by address, so a test staging the row vanishing between the
     * unlocked probe and the lock has to be able to stage it on THIS lookup too.
     */
    #[Override]
    public function findByEmailForUpdate(Email $email): ?User
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::IDENTITY_USER);

        if ($this->onFindByEmailForUpdate instanceof Closure) {
            ($this->onFindByEmailForUpdate)();
        }

        return $this->goneUnderLock ? null : $this->byEmail($email);
    }

    private function byEmail(Email $email): ?User
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
        $preset = $this->preset instanceof User ? [$this->preset] : [];

        return [...$preset, ...$this->seeded, ...$this->saved];
    }
}
