<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Override;

/**
 * The real {@see PasswordResetTokenRepository}, with a hook at the statement that takes the
 * `identity_password_reset_token` row locks during an erasure.
 *
 * Sibling of {@see ProbingUserRepository}, and the second observation point is not redundant with it. The one
 * inside `remove()` can only report what the transaction holds BEFORE the identity row is taken; nothing there
 * can see whether that row is ever actually locked. A `DoctrineUserRepository::remove()` that lost its
 * explicit `flush()` would defer the `DELETE` to the transaction's trailing flush — a bulk DQL delete never
 * flushes the unit of work — so the real acquisition order would become invitation, token, identity, and both
 * assertions at the earlier point would still hold. This hook is where that is caught: by here the identity
 * row must already be locked.
 *
 * @internal
 */
final readonly class ProbingPasswordResetTokenRepository implements PasswordResetTokenRepository
{
    /**
     * @param Closure(): void $beforeBulkDelete runs immediately before the delete that takes this table's row
     *                                          locks, so the probe sees exactly what the transaction held
     *                                          before it
     */
    public function __construct(
        private PasswordResetTokenRepository $inner,
        private Closure $beforeBulkDelete,
    ) {
    }

    #[Override]
    public function save(PasswordResetToken $token): void
    {
        $this->inner->save($token);
    }

    #[Override]
    public function consume(PasswordResetToken $token): bool
    {
        return $this->inner->consume($token);
    }

    #[Override]
    public function findById(string $id): ?PasswordResetToken
    {
        return $this->inner->findById($id);
    }

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        ($this->beforeBulkDelete)();

        return $this->inner->deleteAllForUser($userId);
    }

    #[Override]
    public function deleteExpired(DateTimeImmutable $now): int
    {
        return $this->inner->deleteExpired($now);
    }
}
