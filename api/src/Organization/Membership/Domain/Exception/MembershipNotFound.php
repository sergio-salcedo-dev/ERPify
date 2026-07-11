<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

/**
 * Raised when a user has no membership — a well-formed id that resolves to no user↔organization link. A
 * {@see NotFound} (404) for callers that read a user's organization directly; the session-minting listener
 * catches it and fails closed (503) so login never mints a session with an empty `organizationId`.
 */
final class MembershipNotFound extends DomainException implements NotFound
{
    public static function forUser(string $userId): self
    {
        return new self(
            type: 'membership-not-found',
            title: 'Membership not found.',
            context: ['userId' => $userId],
        );
    }
}
