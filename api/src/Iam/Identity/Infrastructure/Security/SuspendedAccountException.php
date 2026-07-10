<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Thrown post-authentication for a `SUSPENDED` identity: the credential proved, admission refused.
 *
 * Extends `CustomUserMessageAccountStatusException` so the authenticator does NOT re-wrap it into a
 * `BadCredentialsException` (that re-wrap is reserved for pre-identity failures) — it reaches the failure
 * handler intact, which translates it into the specific 403 `account-suspended`. Still an
 * `AccountStatusException`, so the firewall aborts authentication and mints no session at all.
 */
final class SuspendedAccountException extends CustomUserMessageAccountStatusException
{
}
