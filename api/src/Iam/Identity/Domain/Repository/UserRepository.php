<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use SensitiveParameter;

/**
 * Aggregate-lifecycle port for {@see User} backed by the system of record.
 *
 * {@see UserRepository::findByEmail()} is the identifier lookup the session firewall will consume; it
 * takes an already-canonical, non-blank {@see Email}, so the adapter is a pure lookup that never
 * validates. The caller (the session user provider) builds the Email from the raw identifier and maps a
 * rejected value to "user not found".
 */
interface UserRepository
{
    public function save(User $user): void;

    public function remove(User $user): void;

    public function findById(string $id): ?User;

    /**
     * Loads the aggregate under a pessimistic write lock (`SELECT … FOR UPDATE`), re-read from the locked row —
     * never a cached snapshot. Callable only inside a transaction; the lock is the mutex that serialises the
     * password-reset writers on the user row: concurrent forgot requests supersede each other one at a time,
     * and a completion re-checks the identity's status against what an admin may have just committed.
     */
    public function findByIdForUpdate(string $id): ?User;

    public function findByEmail(#[SensitiveParameter] Email $email): ?User;
}
