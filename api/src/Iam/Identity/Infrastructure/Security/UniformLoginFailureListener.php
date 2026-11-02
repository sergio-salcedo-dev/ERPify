<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Turns every login failure into the one neutral 401, as the LAST listener of `LoginFailureEvent`.
 *
 * Every failure collapses to the same answer: a wrong password, an unknown email, a throttled attempt and every
 * admission refusal — `INVITED`, `REVOKED`, `SUSPENDED`, `DEACTIVATED` and a lockout in force — which the walls
 * raise before the password is verified and the authenticator has already re-wrapped into a
 * `BadCredentialsException`. The message is one constant string so those failures stay indistinguishable on the
 * wire; the real cause survives as `previous` for the dev-only debug extension.
 *
 * It throws rather than setting the event's response so the failure reaches `kernel.exception`, where the shared
 * RFC 9457 pipeline answers it and the error tracker's own listener sees it, exactly as every other client error
 * is answered and observed. It throws from the event rather than from
 * {@see ProblemDetailsAuthenticationFailureHandler} because the authenticator dispatches this event only after
 * that handler has returned: thrown there, it would silence every listener below, `login_throttling` included.
 * Being the last listener is the contract — one registered at a lower priority never runs.
 *
 * It acts only on a failure that handler answered, read from the marker it leaves on the request, because the
 * event is dispatched for every authenticator of every firewall: one added later that answers its own failure —
 * a bearer token's `WWW-Authenticate`, or `null` to let an optional credential fall through — keeps that answer.
 */
#[AsEventListener(event: LoginFailureEvent::class, priority: self::PRIORITY)]
final readonly class UniformLoginFailureListener
{
    public const int PRIORITY = -1024;

    public const string GENERIC_FAILURE_MESSAGE = 'Invalid credentials.';

    /**
     * @throws CustomUserMessageAuthenticationException every failure the login handler answered (uniform 401)
     */
    public function __invoke(LoginFailureEvent $event): void
    {
        $marker = ProblemDetailsAuthenticationFailureHandler::UNIFORM_FAILURE_ATTRIBUTE;

        if (true !== $event->getRequest()->attributes->get($marker)) {
            return;
        }

        throw new CustomUserMessageAuthenticationException(
            self::GENERIC_FAILURE_MESSAGE,
            previous: $event->getException(),
        );
    }
}
