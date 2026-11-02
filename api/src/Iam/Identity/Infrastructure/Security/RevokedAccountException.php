<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\DisabledException;

/**
 * Thrown pre-authentication for a `REVOKED` identity — provisioned by an invitation that was withdrawn before
 * it was accepted, so it never held a credential to verify.
 *
 * The sibling of {@see InvitedAccountException} and, like it, a plain `DisabledException` that
 * {@see ProblemDetailsAuthenticationFailureHandler} deliberately does NOT single out: with
 * `expose_security_errors: None` it collapses into the same uniform 401 as an unknown email or a wrong
 * password. That uniformity is the point — answering a withdrawn invitation differently from a pending one
 * would turn the wall into an oracle that reports the outcome of someone else's revocation decision. Every
 * status wall at login is refused the same way, before the password is verified (ADR
 * `identity-invitation-lifecycle.md` D4, D12): a password alone proves no identity a refusal may reveal.
 */
final class RevokedAccountException extends DisabledException
{
}
