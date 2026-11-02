<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\LockedException;

/**
 * Thrown before verification for an identity whose lockout is in force, so no password is checked while it
 * stands ({@see LockedIdentityLoginRefusalListener}).
 *
 * A plain `AccountStatusException` (via `LockedException`): the authenticator re-wraps it into the uniform
 * pre-identity 401, so the lock is not disclosed to the caller and never confirms a guess. The owner learns of it
 * from the lockout notice mail.
 */
final class LockedAccountException extends LockedException
{
}
