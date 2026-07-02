<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Domain\Repository;

use Erpify\Backoffice\Identity\Domain\Entity\User;

/**
 * Aggregate-lifecycle port for {@see User} backed by the system of record.
 *
 * {@see UserRepository::findByEmail()} is the identifier lookup the session firewall will consume; it
 * expects the canonical (lower-cased) email the aggregate stores.
 */
interface UserRepository
{
    public function save(User $user): void;

    public function remove(User $user): void;

    public function findById(string $id): ?User;

    public function findByEmail(string $email): ?User;
}
