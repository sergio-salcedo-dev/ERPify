<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Projection;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;

/**
 * One row of the identity register — a read-only projection built by a DQL `SELECT NEW` over `User`,
 * single FROM with no JOIN. It is a query result, never the persisted aggregate; it lives in `Domain/`
 * because the read port {@see \Erpify\Iam\Identity\Domain\Repository\UserSearchRepository} returns it,
 * mirroring the sibling {@see \Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow}.
 *
 * Selecting explicit columns is security-by-construction: a projection literally cannot leak
 * `password_hash` / `failedAttempts` / `lockedUntil`, because it never selects them (the {@see User}
 * aggregate deliberately stays out of the audit trail and out of the serializer for the same reason).
 *
 * It carries the wire fields PLUS the sortable keyset columns (`createdAt` / `updatedAt`) the engine's
 * {@see \Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorPositionExtractor} reads
 * off each result row by bare property path — so they are public readonly properties even though the wire
 * resource pre-formats them. Doctrine applies field-type conversion inside `SELECT NEW`, so the JSON
 * `roles` column hydrates as a `list<string>`, `status` as its {@see IdentityStatus} enum and the two
 * timestamps as `DateTimeImmutable`; the resource mapper resolves the enum and roles to their wire form.
 */
final readonly class UserRow
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public array $roles,
        public IdentityStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
