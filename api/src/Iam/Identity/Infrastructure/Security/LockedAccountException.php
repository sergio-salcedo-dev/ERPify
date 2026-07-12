<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Thrown post-authentication for an identity whose lockout is still in force: the credential proved, admission
 * refused until the lock lapses.
 *
 * Extends `CustomUserMessageAccountStatusException` so the authenticator does NOT re-wrap it into a
 * `BadCredentialsException` (that re-wrap is reserved for pre-identity failures) — it reaches the failure
 * handler intact, which translates it into the specific 403 `account-locked`. A plain `AccountStatusException`
 * or Symfony's own `LockedException` would collapse to the uniform 401 under `expose_security_errors: None`,
 * exposing the lock to no one and defeating the actionable wall. Still an `AccountStatusException`, so the
 * firewall aborts authentication and mints no session at all.
 */
final class LockedAccountException extends CustomUserMessageAccountStatusException
{
}
