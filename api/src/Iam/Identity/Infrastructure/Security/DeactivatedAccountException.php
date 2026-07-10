<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Thrown post-authentication for a `DEACTIVATED` identity: the credential proved, admission refused.
 *
 * Extends `CustomUserMessageAccountStatusException` (like {@see SuspendedAccountException}) so it is not
 * re-wrapped into a `BadCredentialsException` and reaches the failure handler intact, which translates it
 * into the generic 403 `forbidden` — a retired identity has no actionable next step, unlike a reversible
 * suspension. Still an `AccountStatusException`, so no session is minted.
 */
final class DeactivatedAccountException extends CustomUserMessageAccountStatusException
{
}
