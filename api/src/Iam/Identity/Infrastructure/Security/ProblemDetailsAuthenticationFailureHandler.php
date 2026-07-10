<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Routes a login failure through the shared RFC 9457 pipeline instead of Symfony's default handler, which
 * would emit its own `{"error": ...}` body on `kernel.request` and bypass the error contract. Throwing lets
 * the exception reach `kernel.exception`, where the pipeline builds the Problem Details — reusing
 * correlation-id minting, the tiered log line, body cap and redaction with zero duplication.
 *
 * The failure is graded by how far the login got, which is exactly the exception type the checker raised:
 *
 * - A POST-identity admission failure (`SUSPENDED` / `DEACTIVATED` — the credential proved, then the
 *   identity was refused) graduates OUT of the uniform 401 into a 403 `Forbidden` `DomainException`. Neither
 *   wall carries an `AccessDeniedException` in its chain, so `UnauthenticatedAccessListener` (which would
 *   rewrite that to a 401) leaves them alone.
 * - Every PRE-identity failure — a wrong password, an unknown email, and the `INVITED` case the authenticator
 *   already re-wrapped into a `BadCredentialsException` — collapses to a single neutral 401. The message is
 *   normalised to one constant string so those failures stay indistinguishable on the wire; the real cause
 *   survives as `previous` for the dev-only debug extension.
 */
final readonly class ProblemDetailsAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    private const string GENERIC_FAILURE_MESSAGE = 'Invalid credentials.';

    /**
     * @throws AccountSuspended                         a SUSPENDED identity's proven login (403 specific)
     * @throws AccountDeactivated                       a DEACTIVATED identity's proven login (403 generic)
     * @throws CustomUserMessageAuthenticationException any pre-identity failure (uniform 401)
     *
     * @return never
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof SuspendedAccountException) {
            throw new AccountSuspended();
        }

        if ($exception instanceof DeactivatedAccountException) {
            throw new AccountDeactivated();
        }

        throw new CustomUserMessageAuthenticationException(self::GENERIC_FAILURE_MESSAGE, previous: $exception);
    }
}
