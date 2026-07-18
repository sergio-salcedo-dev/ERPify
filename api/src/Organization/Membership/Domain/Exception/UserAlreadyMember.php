<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when a second membership is granted to a user that already belongs — a user has exactly one
 * membership (the authoritative user↔organization link).
 *
 * A `Conflict` (409): the request targets a valid resource but collides with the current state. On the invitation
 * alta the duplicate-email case is caught earlier by `#[UniqueEntity(email)]` (422), so this stays a defensive
 * guard for the membership-level collision — never a raw 500.
 */
final class UserAlreadyMember extends DomainException implements Conflict
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
