<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when taking an identity out of the active-administrator set would leave the organization with zero
 * active `ADMIN`s — the last active administrator can be neither suspended, deactivated nor erased.
 *
 * A {@see Conflict} (409): the request is well-formed and authorized but collides with a state invariant,
 * like `BankInUseException`. Distinct from {@see AccountSuspended} (403, the login wall): this is the guard
 * on the write, not the response to a walled login.
 *
 * Erasure does not raise it. An identity carrying `ADMIN` is refused earlier and more specifically by
 * {@see AdministratorErasureRequiresDemotion}, and the last active administrator necessarily carries the role,
 * so this invariant is reached only through the transitions that can shrink the set — the demotion the erasure
 * refusal sends the caller to, and the status change.
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
