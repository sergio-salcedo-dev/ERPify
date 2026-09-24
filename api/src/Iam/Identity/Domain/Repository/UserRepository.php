<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
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

    /**
     * The same identifier lookup under a pessimistic write lock (`SELECT … FOR UPDATE`), re-read from the
     * locked row rather than from a cached snapshot. Callable only inside a transaction.
     *
     * It exists because the lockout counter is written from a path that has only the ADDRESS: recording a
     * failed login resolves the identity by email, and deciding whether to increment or to trip the lock on
     * an unlocked read lets that decision be taken against state another transaction has already replaced —
     * a recovery-secret redemption clearing the lock, or an administrator unlocking the account, both
     * followed by a write that puts `locked_until` back.
     */
    public function findByEmailForUpdate(#[SensitiveParameter] Email $email): ?User;

    /**
     * Replaces the stored credential with `$replacement` if, and only if, the row still holds `$expected`, and
     * answers whether it did — a compare-and-swap decided by the store in one statement. Callable only inside
     * a transaction. For a re-encoding of the SAME secret (a hash upgraded to the configured hasher), never for
     * a credential change: it records no fact and leaves `updated_at` alone, because nothing about the secret
     * moved and that column is the user register's keyset sort key.
     *
     * It is a store operation rather than an aggregate method because the aggregate is the wrong place to read
     * the current credential from: loading it under the lock re-hydrates the instance the caller already
     * holds — on the login path, the one the session serialises — so a refusal would leave that instance
     * advanced to a credential the caller never proved. Here a refusal touches no instance at all; on success
     * an already-loaded aggregate is brought in line with the row, so a copy of it compared against the row
     * later agrees.
     */
    public function replacePasswordHashIfUnchanged(
        string $id,
        #[SensitiveParameter]
        HashedPassword $expected,
        #[SensitiveParameter]
        HashedPassword $replacement,
    ): bool;
}
