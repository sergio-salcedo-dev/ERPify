<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\DisabledException;

/**
 * Thrown before verification for a `SUSPENDED` identity, so its password is never checked.
 *
 * A plain `AccountStatusException` (via `DisabledException`), like {@see InvitedAccountException}: with
 * `expose_security_errors: None` the authenticator re-wraps it into a `BadCredentialsException`, so the refusal
 * is the uniform pre-identity 401 and never tells a guesser the password was right.
 */
final class SuspendedAccountException extends DisabledException
{
}
