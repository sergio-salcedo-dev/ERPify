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
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final readonly class SessionAdmissionGate
{
    public const int PRIORITY = 7;

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

        if (!$this->apiRequestMatcher->matches($event->getRequest())) {
            return;
        }

        if (!$this->trustResolver->isFullFledged($this->tokenStorage->getToken())) {
            return;
        }

        $sessionId = $this->currentSession->get();

        if (!$sessionId instanceof SessionId) {
            throw SessionNoLongerActive::forRequest();
        }

        // findActiveById applies `status = ACTIVE AND expires_at > now` and raises SessionStoreUnavailable (503)
        // when the store is down — the gate lets that propagate, never falling open.
        if (!$this->sessions->findActiveById($sessionId) instanceof Session) {
            throw SessionNoLongerActive::forRequest();
        }
    }
}
