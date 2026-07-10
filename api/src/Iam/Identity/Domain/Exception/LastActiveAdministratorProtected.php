<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when suspending or deactivating an identity would leave the organization with zero active `ADMIN`s —
 * the last active administrator can be neither suspended nor deactivated.
 *
 * A {@see Conflict} (409): the request is well-formed and authorized but collides with a state invariant,
 * like `BankInUseException`. Distinct from {@see AccountSuspended} (403, the login wall): this is the guard
 * on the write, not the response to a walled login.
 */
final class LastActiveAdministratorProtected extends DomainException implements Conflict
{
    public static function forUser(string $userId): self
    {
        return new self(
            type: 'last-active-administrator-protected',
            title: 'Cannot suspend or deactivate the last active administrator of the organization.',
            context: ['userId' => $userId],
        );
    }
}
