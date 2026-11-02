<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\DisabledException;

/**
 * Thrown before verification for a `DEACTIVATED` identity, so its password is never checked.
 *
 * A plain `AccountStatusException` (via `DisabledException`), like {@see SuspendedAccountException}: the
 * authenticator re-wraps it into the uniform pre-identity 401.
 */
final class DeactivatedAccountException extends DisabledException
{
}
