<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when a second membership is granted to a user that already belongs — a user has exactly one
 * membership (the authoritative user↔organization link).
 *
 * Marker-less by design: it guards a CLI bootstrap step, not an HTTP surface in this slice.
 */
final class UserAlreadyMember extends DomainException
{
    public function __construct(string $userId)
    {
        parent::__construct(
            type: 'user-already-member',
            title: 'This user already belongs to the organization.',
            context: ['userId' => $userId],
        );
    }
}
