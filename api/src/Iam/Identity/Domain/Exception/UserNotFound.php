<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

/**
 * Raised when a lifecycle operation targets an identity id that resolves to no row — a well-formed id with
 * no user, which the RFC 9457 pipeline maps to a 404 through the {@see NotFound} marker (distinct from a
 * malformed id, guarded earlier by `Uuid::ensure` as a 400 `invalid-uuid`).
 */
final class UserNotFound extends DomainException implements NotFound
{
    public static function withId(string $id): self
    {
        return new self(
            type: 'user-not-found',
            title: 'User not found.',
            context: ['userId' => $id],
        );
    }
}
