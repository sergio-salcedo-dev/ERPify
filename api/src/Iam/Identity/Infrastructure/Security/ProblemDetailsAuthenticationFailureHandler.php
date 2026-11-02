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
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Handles a login failure: it records the failed attempt against the target identity (feeding the persisted
 * lockout) and RETURNS a provisional response, which nothing ever sends.
 *
 * Returning is what lets `AuthenticatorManager::handleAuthenticationFailure()` go on to dispatch
 * `LoginFailureEvent`: it calls this handler first and dispatches the event only once the handler has returned,
 * so a handler that throws silences every listener of that event — `login_throttling` among them, which only
 * peeks its budget before the credentials are checked and spends it on that event, so a throwing handler leaves
 * the throttle declared and inert. The uniform answer is thrown afterwards by
 * {@see UniformLoginFailureListener}, the last listener of the event, so it still reaches `kernel.exception` and
 * the shared RFC 9457 pipeline — correlation id, tiered log line, error tracking, body cap and redaction — rather
 * than Symfony's own `{"error": ...}` body or a second pipeline built here.
 *
 * The failed-attempt increment lives HERE rather than in a listener because the handler is the one hook the
 * authenticator calls on every failure before anything else can stop the chain. The record is by EMAIL (the
 * submitted identifier): an unknown one writes no row and raises no event while paying the same round trips as
 * a known one, and a malformed one is a no-op — keeping a pre-identity failure indistinguishable; the domain
 * caps the counter and, once locked, no-ops further attempts, so no unbounded growth. An admission refusal is
 * recorded like any other failure although no password was checked: that it counts nothing rests on
 * `User::recordFailedAttempt()` doing nothing for a non-`ACTIVE` or locked identity, while the transaction and
 * the row lock are still taken so the refusal pays the same round trips. A throttled attempt
 * ({@see TooManyLoginAttemptsAuthenticationException}) is NOT recorded: it never reached a credential check, so
 * counting it would let a single IP drive the persistent per-identity lock that the per-IP throttle exists to
 * stop short of.
 */
final readonly class ProblemDetailsAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    /**
     * Marks a request whose failure this handler answered, so {@see UniformLoginFailureListener} replaces only
     * this handler's placeholder and leaves any other authenticator's failure response alone.
     */
    public const string UNIFORM_FAILURE_ATTRIBUTE = '_erpify_uniform_login_failure';

    public function __construct(private LoginAttemptRegistrar $loginAttempts)
    {
    }

    /**
     * The returned response is a placeholder for the authenticator's contract: {@see UniformLoginFailureListener}
     * replaces it with the uniform 401 by throwing before the authenticator can hand it to the kernel.
     */
    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $this->recordFailureBestEffort($request);
        }

        $request->attributes->set(self::UNIFORM_FAILURE_ATTRIBUTE, true);

        return new Response(status: Response::HTTP_UNAUTHORIZED);
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
