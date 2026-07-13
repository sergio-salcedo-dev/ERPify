<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses a cross-site POST to the invitation-accept endpoint — the same-origin gate that is the PRIMARY CSRF
 * control for this public, pre-identity write (the opaque single-use token being the other). A browser always
 * sends `Origin` on a POST, so a missing or mismatched header is treated as cross-site and rejected before the
 * use case runs, without mutating any state. Mirrors the login
 * {@see \Erpify\Iam\Identity\Infrastructure\Http\LoginOriginListener} keyed on this route; consolidating the two
 * behind the native stateless check is a follow-up.
 *
 * Priority 9 runs it after `RouterListener` (so `_route` resolves) and before the firewall; a rejection throws
 * so the shared RFC 9457 pipeline answers a 403 `forbidden`, uniformly for every cross-site POST.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final readonly class AcceptInvitationOriginListener
{
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (AcceptInvitationController::ROUTE_NAME !== $request->attributes->get('_route')) {
            return;
        }

        if ($request->headers->get('Origin') === $request->getSchemeAndHttpHost()) {
            return;
        }

        throw new AccessDeniedHttpException('Cross-origin request rejected.');
    }
}
