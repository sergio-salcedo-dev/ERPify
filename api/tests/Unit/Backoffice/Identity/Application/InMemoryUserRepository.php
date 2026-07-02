<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Identity\Application;

use Erpify\Backoffice\Identity\Domain\Email;
use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Repository\UserRepository;
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
        $this->removeCalled = true;
    }

    #[Override]
    public function findById(string $id): ?User
    {
        return $this->preset;
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
