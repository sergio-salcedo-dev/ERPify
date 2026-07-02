<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Domain\Repository;

use Erpify\Backoffice\Identity\Domain\Email;
use Erpify\Backoffice\Identity\Domain\Entity\User;

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

    public function findByEmail(Email $email): ?User;
}
