<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Routes a login failure through the shared RFC 9457 pipeline instead of Symfony's default handler, which
 * would emit its own `{"error": ...}` body on `kernel.request` and bypass the error contract. Throwing lets
 * the exception reach `kernel.exception`, where `ExceptionResponder` (priority 16, above the firewall) builds
 * the 401 `unauthenticated` Problem Details via the existing `AuthenticationException` bridge — reusing
 * correlation-id minting, the tiered log line, body cap and redaction with zero duplication.
 *
 * The message is normalised to one constant string: Symfony otherwise says "The presented password is
 * invalid." for a wrong password on a known email but "Bad credentials." for an unknown email, which lets an
 * attacker enumerate accounts. The 401 arm surfaces `getMessage()` verbatim, so a single message here makes
 * every failure indistinguishable on the wire; the real cause survives as `previous` for dev-only debug.
 */
final readonly class ProblemDetailsAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    private const string GENERIC_FAILURE_MESSAGE = 'Invalid credentials.';

    /**
     * @throws AuthenticationException always — the pipeline formats it downstream
     *
     * @return never
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        throw new CustomUserMessageAuthenticationException(self::GENERIC_FAILURE_MESSAGE, previous: $exception);
    }
}
