<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Doctrine\DBAL\Exception as DbalException;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
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
 * hook that runs on every failure. The record is by EMAIL (the submitted identifier): an unknown one writes no
 * row and raises no event while paying the same round trips as a known one, and a malformed one is a no-op —
 * keeping a pre-identity failure indistinguishable; the domain
 * caps the counter and, once locked, no-ops further attempts, so no unbounded growth. A throttled attempt
 * ({@see TooManyLoginAttemptsAuthenticationException}) is NOT recorded: it never reached a credential check, so
 * counting it would let a single IP drive the persistent per-identity lock that the per-IP throttle exists to
 * stop short of.
 *
 * Every failure collapses to a single neutral 401: a wrong password, an unknown email, and every admission
 * refusal — `INVITED`, `REVOKED`, `SUSPENDED`, `DEACTIVATED` and a lockout in force — which the walls raise
 * before the password is verified and the authenticator has already re-wrapped into a
 * `BadCredentialsException`. The message is normalised to one constant string so those failures stay
 * indistinguishable on the wire; the real cause survives as `previous` for the dev-only debug extension.
 */
final readonly class ProblemDetailsAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    private const string GENERIC_FAILURE_MESSAGE = 'Invalid credentials.';

    public function __construct(private LoginAttemptRegistrar $loginAttempts)
    {
    }

    /**
     * @throws CustomUserMessageAuthenticationException every login failure (uniform 401)
     *
     * @return never
     */
    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $this->recordFailureBestEffort($request);
        }

        throw new CustomUserMessageAuthenticationException(self::GENERIC_FAILURE_MESSAGE, previous: $exception);
    }

    /**
     * Records the failed attempt against the submitted identity, best-effort. A store fault here must NOT turn the
     * uniform 401 into a leaked 500 or 503, nor let a resolved email diverge from an unknown one on the wire. Both
     * reach the store, but only an existing identity locks a row and writes, so only it can meet a deadlock, a lock
     * timeout or a referential fault — which the transaction manager translates into {@see TransientTransactionFailure}
     * (503) and {@see ReferentialIntegrityViolation} (409). Those are absorbed with a {@see DbalException}, and the
     * response stays the uniform 401. The lost increment is tolerable during a database
     * incident (the per-IP throttle still caps the flood); this differs from the success path's retryable-503 remap
     * because the failure path has nothing downstream that needs the shared unit of work, so it can swallow and stay
     * neutral.
     */
    private function recordFailureBestEffort(Request $request): void
    {
        try {
            $this->loginAttempts->recordFailure($this->submittedIdentifier($request));
        } catch (DbalException|ReferentialIntegrityViolation|TransientTransactionFailure) {
            // Best-effort: a store fault recording the attempt must not surface to the client as a 500 or an
            // enumeration oracle; the neutral graded response wins over persisting this one increment.
        }
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
