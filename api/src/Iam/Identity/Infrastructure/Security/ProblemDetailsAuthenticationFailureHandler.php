<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountLocked;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Override;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Handles a login failure: it records the failed attempt against the target identity (feeding the persisted
 * lockout) and routes the response through the shared RFC 9457 pipeline instead of Symfony's default handler,
 * which would emit its own `{"error": ...}` body on `kernel.request` and bypass the error contract. Throwing
 * lets the exception reach `kernel.exception`, where the pipeline builds the Problem Details — reusing
 * correlation-id minting, the tiered log line, body cap and redaction with zero duplication.
 *
 * The failed-attempt increment lives HERE, not in a `LoginFailureEvent` listener: the authenticator calls this
 * handler (`AuthenticatorManager::handleAuthenticationFailure`) and only dispatches `LoginFailureEvent`
 * afterwards, so a handler that throws — as this one always does — preempts that event; the handler is the sole
 * hook that runs on every failure. The record is by EMAIL (the submitted identifier), so an unknown or
 * malformed one is a no-op — no row, no event — keeping a pre-identity failure indistinguishable; the domain
 * caps the counter and, once locked, no-ops further attempts, so no unbounded growth. A throttled attempt
 * ({@see TooManyLoginAttemptsAuthenticationException}) is NOT recorded: it never reached a credential check, so
 * counting it would let a single IP drive the persistent per-identity lock that the per-IP throttle exists to
 * stop short of.
 *
 * The response is graded by how far the login got, which is exactly the exception type the checker raised:
 *
 * - A POST-identity admission failure (`SUSPENDED` / `DEACTIVATED` / locked — the credential proved, then the
 *   identity was refused) graduates OUT of the uniform 401 into a 403 `Forbidden` `DomainException`. None of
 *   the walls carries an `AccessDeniedException` in its chain, so `UnauthenticatedAccessListener` (which would
 *   rewrite that to a 401) leaves them alone.
 * - Every PRE-identity failure — a wrong password, an unknown email, and the `INVITED` case the authenticator
 *   already re-wrapped into a `BadCredentialsException` — collapses to a single neutral 401. The message is
 *   normalised to one constant string so those failures stay indistinguishable on the wire; the real cause
 *   survives as `previous` for the dev-only debug extension.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — the single auth-failure→RFC 9457 translation boundary, so
 * it references both the checker-exception family (suspended/deactivated/locked/throttled) and their
 * problem-detail counterparts by design; the coupling is the mapping, not incidental.
 */
final readonly class ProblemDetailsAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    private const string GENERIC_FAILURE_MESSAGE = 'Invalid credentials.';

    public function __construct(private LoginAttemptRegistrar $loginAttempts)
    {
    }

    /**
     * @throws AccountSuspended                         a SUSPENDED identity's proven login (403 specific)
     * @throws AccountDeactivated                       a DEACTIVATED identity's proven login (403 generic)
     * @throws AccountLocked                            a locked identity's proven login (403 specific)
     * @throws CustomUserMessageAuthenticationException any pre-identity failure (uniform 401)
     *
     * @return never
     */
    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $this->loginAttempts->recordFailure($this->submittedIdentifier($request));
        }

        if ($exception instanceof SuspendedAccountException) {
            throw new AccountSuspended();
        }

        if ($exception instanceof DeactivatedAccountException) {
            throw new AccountDeactivated();
        }

        if ($exception instanceof LockedAccountException) {
            throw new AccountLocked();
        }

        throw new CustomUserMessageAuthenticationException(self::GENERIC_FAILURE_MESSAGE, previous: $exception);
    }

    /**
     * The email the caller submitted, read from the same `email` field the `json_login` firewall keys on. A
     * missing or non-JSON body, or a non-scalar `email`, raises a `RequestExceptionInterface`; it yields an
     * empty identifier, which the registrar treats as "no identity", so a malformed request stays on the
     * neutral failure path rather than escaping as an error.
     */
    private function submittedIdentifier(Request $request): string
    {
        try {
            return $request->getPayload()->getString('email');
        } catch (RequestExceptionInterface) {
            return '';
        }
    }
}
