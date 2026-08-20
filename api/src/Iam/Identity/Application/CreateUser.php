<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Shared\Validation\Application\Validator;
use SensitiveParameter;

/**
 * Registers a new user: the server mints the id (UUID v7), the aggregate enforces its invariants (canonical
 * email, distinct roles) and {@see Validator::ensure()} runs the entity constraints — `#[Assert\Email]` and
 * the `#[UniqueEntity(email)]` uniqueness guard — before persisting, propagating its validation failure (e.g.
 * a duplicate email) to the caller. That guard is a SELECT and the write is an INSERT, so an address
 * committed in between still reaches the unique index; the port answers that with a conflict carrying no
 * address, rather than the driver's own message, which names one. The credential arrives already hashed;
 * hashing stays in Infrastructure.
 */
final readonly class CreateUser
{
    public function __construct(
        private UserRepository $users,
        private Validator $validator,
    ) {
    }

    public function create(#[SensitiveParameter] string $email, HashedPassword $password, Role ...$roles): User
    {
        $user = User::register(Uuid::generate(), $email, $password, ...$roles);

        $this->validator->ensure($user);
        $this->users->save($user);

        return $user;
    }
}
