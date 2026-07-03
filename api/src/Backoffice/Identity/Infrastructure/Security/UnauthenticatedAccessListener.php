<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Security;

use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;
use Throwable;

/**
 * Restores the 401-vs-403 distinction the access-control firewall loses on this stack. When `access_control`
 * denies an `/api` request the firewall raises an {@see AccessDeniedException} regardless of whether the caller
 * is unauthenticated or merely under-privileged; the Problem Details pipeline then maps that type to 403. But an
 * anonymous caller must get 401 ("log in"), not 403 ("you may not"). Symfony's own firewall listener would draw
 * that line via an entry point — except it runs at priority 1, and {@see ExceptionResponder} (priority 16) has
 * already called `setResponse()`, which stops propagation on {@see ExceptionEvent} (a `RequestEvent`), so the
 * firewall listener never runs.
 *
 * This listener sits above both (priority 40 > the audit listener's 32 > 16) and makes the call the firewall no
 * longer can: for an `/api` main request whose throwable chain holds an `AccessDeniedException`, if no
 * full-fledged user is authenticated it rewrites the throwable to an {@see InsufficientAuthenticationException}
 * (an {@see AuthenticationException}) and returns without setting a response — so `ExceptionResponder` emits 401
 * through its existing `AuthenticationException` arm. It is the sole 401-for-unauthenticated-access site, leaving
 * the Problem Details factory untouched (and its constant-time 401/403 branching intact). An
 * authenticated-but-denied caller is left alone: the chain stays an `AccessDeniedException`, the audit listener
 * records it, and 403 is emitted — the behaviour Epic 3's role gates rely on.
 *
 * Two ordering details are load-bearing: running above 32 means the rewrite happens before the audit listener,
 * so an anonymous probe is no longer recorded as an `ACCESS_DENIED` security event (only genuine
 * authenticated denials are). And the replacement carries no `previous` `AccessDeniedException`, so the audit
 * listener's chain walk cannot re-discover it.
 */
final readonly class UnauthenticatedAccessListener
{
    public const int PRIORITY = 40;

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'security.authentication.trust_resolver')]
        private AuthenticationTrustResolverInterface $trustResolver,
        private ApiRequestMatcher $apiRequestMatcher,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->apiRequestMatcher->matches($event->getRequest())) {
            return;
        }

        if (!$this->isAccessDenied($event->getThrowable())) {
            return;
        }

        if ($this->trustResolver->isFullFledged($this->tokenStorage->getToken())) {
            return;
        }

        $event->setThrowable(new InsufficientAuthenticationException('Authentication required.'));
    }

    private function isAccessDenied(Throwable $throwable): bool
    {
        // Cycle-safe walk (spl_object_id seen-set): a malformed/rewrapped chain with a reused previous
        // instance MUST NOT loop and stall the FrankenPHP worker — the same guard ProblemDetailsFactory
        // applies, needed here because this listener walks the chain first (priority 40).
        $seen = [];

        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            $id = \spl_object_id($current);

            if (isset($seen[$id])) {
                return false;
            }

            $seen[$id] = true;

            if ($current instanceof AccessDeniedException) {
                return true;
            }
        }

        return false;
    }
}
