<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\DisabledException;

/**
 * Thrown pre-authentication for an `INVITED` identity — provisioned but not yet activated, so it has no
 * credential to verify.
 *
 * A plain {@see \Symfony\Component\Security\Core\Exception\AccountStatusException} (via `DisabledException`),
 * deliberately NOT a `CustomUserMessageAccountStatusException`: with `expose_security_errors: None` the
 * authenticator re-wraps it into a `BadCredentialsException` before the failure handler runs, so it collapses
 * into the single uniform 401 that makes a pre-identity login indistinguishable from an unknown email or a
 * wrong password. Thrown in `checkPreAuth`, so the password is never verified for an invited identity.
 */
final class InvitedAccountException extends DisabledException
{
}
