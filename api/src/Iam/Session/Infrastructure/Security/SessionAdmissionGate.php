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
 * **Refusing also drops the native session** ({@see refuse()}), the same way explicit sign-out does — and what
 * that buys is narrower than "the caller becomes anonymous", which is why the narrow statement is the one
 * written here. A refusal does not de-authenticate: `ContextListener` is a global `kernel.response` listener,
 * this gate's own `getToken()` is what marks the firewall as having run, and it re-serialises the token that
 * is still in storage into the session `invalidate()` just regenerated. So the caller arrives full-fledged
 * again on the next request and is refused HERE a second time, never at `access_control`, and a
 * `PUBLIC_ACCESS` route keeps answering 401 to a stale-cookie bearer. Measured against the running stack,
 * following the regenerated cookie: `GET /api/v1/sessions` is 401 `session-expired`, and so is
 * `GET /api/v1/health`, which is the route that would have to be served for the anonymity reading to be true.
 *
 * What the invalidation removes is the `iamSessionId` correlation, so every refusal after the first
 * short-circuits at {@see CurrentSessionReference} and never reaches `findActiveById` — no database
 * round-trip for a caller bearing a dead cookie. That is the whole of the gain, and its price is a session
 * destroy, a regenerate and a `Set-Cookie` per refused request. Neither side of that trade is benchmarked,
 * so it is stated as a shape and not as a win.
 *
 * **The admitted session is published on the Request** under {@see ADMITTED_SESSION_ATTRIBUTE}, read back by
 * {@see admittedSession()}. The row is already loaded here on every authenticated request, so a controller that
 * needs it reads it from there instead of issuing the same lookup. The carrier is the Request and never this
 * service or a static:
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
     * Drops the native session, then raises the 401. The `hasSession()` guard is a TEST contract, not a
     * production one, and saying so is the point: `AbstractSessionListener` puts a session factory on every
     * main request at priority 128, long before this gate at 7, and `Request::hasSession()` answers on the
     * factory — so in the running application it is always true and `getSession()` can never raise here. What
     * the guard keeps working is the bare `Request::create()` a unit test hands to the no-correlation branch.
     * It is not the protection a session-less caller would need either: `getSession()` would materialise and
     * start one, and `invalidate()` would hand that caller a cookie.
     *
     * The BODY and the STATUS are unchanged — the throw is the only thing the Problem Details pipeline sees —
     * but the response is not: dropping the session regenerates its id, so the 401 carries a `Set-Cookie`, and
     * touching the session makes the framework add `Cache-Control: private, must-revalidate` with an `Expires`
     * beside it. `session.feature` asserts the cookie and its flags, because a regenerated session cookie that
     * lost `httponly` or `samesite` would be a worse defect than the round-trip the invalidation saves.
     */
    private function refuse(Request $request): never
    {
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        throw SessionNoLongerActive::forRequest();
    }
}
