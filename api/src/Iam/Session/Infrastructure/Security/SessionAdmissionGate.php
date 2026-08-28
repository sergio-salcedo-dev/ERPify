<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The Session Admission Gate: every authenticated `/api` request re-reads its `iamSessionId` correlation, loads
 * the registry row, and forces logout unless the session is admissible — so "authenticated" means "has a live,
 * revocable session", not merely "holds a valid cookie". It runs at priority 7, just after the firewall's
 * `ContextListener` (8) restores the token and before the controller.
 *
 * Three guards are load-bearing. `isMainRequest` skips sub-requests; the `^/api` matcher skips the proxied PWA
 * and `/.well-known/mercure`; `isFullFledged` skips anonymous requests to `PUBLIC_ACCESS` routes (login,
 * health, dev) — without them the gate would 401 legitimate anonymous traffic the firewall admits.
 *
 * The two outcomes are deliberately distinct. A missing, revoked or time-expired session is a
 * {@see SessionNoLongerActive} (an `Unauthenticated` marker → 401 "re-login"): NOT an `AccessDeniedException`
 * (403 for a full-fledged token) and NOT a Symfony `AuthenticationException` (which would flood Sentry). An
 * unreachable store surfaces as {@see \Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable} from the
 * repository (→ 503) — the gate never swallows it, so it fails closed rather than open.
 *
 * **Refusing also drops the native session** ({@see refuse()}), the same way explicit sign-out does. Without
 * that, the cookie behind a refused request survives to its own TTL and every subsequent request — including
 * the `PUBLIC_ACCESS` ones an anonymous caller gets served with no query at all — arrives full-fledged and
 * spends another `findActiveById` round-trip, so bearing a revoked cookie costs the deployment more than
 * bearing none. One refusal now makes the next request anonymous, refused at `access_control`.
 *
 * **The admitted session is published on the Request** under {@see ADMITTED_SESSION_ATTRIBUTE}, read back by
 * {@see admittedSession()}. The row is already loaded here on every authenticated request, and the controllers
 * that need it were loading it a second time. The carrier is the Request and never this service or a static:
 * FrankenPHP runs in worker mode, so the container and every service in it outlive the request, and a session
 * held anywhere container-scoped would be read by the NEXT request — an admission defect far worse than the
 * duplicate query it saves. A Request dies with its request by construction.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final readonly class SessionAdmissionGate
{
    public const int PRIORITY = 7;

    /**
     * Request-attribute key carrying the {@see Session} this request was admitted with. Underscore-prefixed
     * like the framework's own internal attributes, and matched by {@see Session} type on the way out, so
     * anything else stored under the key reads back as "not admitted here" rather than as a session.
     */
    public const string ADMITTED_SESSION_ATTRIBUTE = '_iam_admitted_session';

    public function __construct(
        private CurrentSessionReference $currentSession,
        private SessionRepository $sessions,
        private TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'security.authentication.trust_resolver')]
        private AuthenticationTrustResolverInterface $trustResolver,
        private ApiRequestMatcher $apiRequestMatcher,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->apiRequestMatcher->matches($request)) {
            return;
        }

        if (!$this->trustResolver->isFullFledged($this->tokenStorage->getToken())) {
            return;
        }

        $sessionId = $this->currentSession->get();

        if (!$sessionId instanceof SessionId) {
            $this->refuse($request);
        }

        // findActiveById applies `status = ACTIVE AND expires_at > now` and raises SessionStoreUnavailable (503)
        // when the store is down — the gate lets that propagate, never falling open.
        $session = $this->sessions->findActiveById($sessionId);

        if (!$session instanceof Session) {
            $this->refuse($request);
        }

        $request->attributes->set(self::ADMITTED_SESSION_ATTRIBUTE, $session);
    }

    /**
     * The session the gate admitted this request with, or `null` when the gate did not run for it — a
     * sub-request, a non-`/api` path, or an anonymous request to a `PUBLIC_ACCESS` route. A caller that needs
     * the row regardless falls back to its own lookup; this is a saved query, never an authorization decision.
     */
    public static function admittedSession(Request $request): ?Session
    {
        $stored = $request->attributes->get(self::ADMITTED_SESSION_ATTRIBUTE);

        return $stored instanceof Session ? $stored : null;
    }

    /**
     * Drops the native session, then raises the 401. Guarded on `hasSession()` because the gate runs on every
     * `/api` request and a request carrying no session at all must still refuse with the same 401 — reaching
     * `getSession()` there would raise a `SessionNotFoundException` and turn the refusal into a 500.
     *
     * The response shape is unchanged: the throw is the only thing the Problem Details pipeline sees.
     */
    private function refuse(Request $request): never
    {
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        throw SessionNoLongerActive::forRequest();
    }
}
