<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses a cross-site POST to the session-login endpoint. `json_login` attends the login on the route's
 * `_format: json` default, so it fires regardless of `Content-Type`: a cross-site `<form enctype="text/plain">`
 * with a JSON-shaped body reaches it as a CORS "simple request" (no preflight) and would establish a session —
 * login CSRF / forced login. Same-origin deployment, the non-broadened CORS policy and `SameSite=Lax` do NOT
 * stop that (CORS governs reading the response, not sending the request; forced login sets a fresh cookie). This
 * guard requires the browser-set `Origin` to equal the request's own origin before the firewall authenticates —
 * the same same-origin check the stateless double-submit CSRF token will reuse for mutating routes
 * (wire-on-consumer). A browser always sends `Origin` on a POST, so a missing header is treated as cross-site.
 *
 * Priority 9 runs it after `RouterListener` (32, so `_route` resolves) and before the firewall (8). A rejection
 * throws so the shared RFC 9457 pipeline answers a 403 `forbidden`; it runs before any credential check,
 * uniformly for every cross-site POST, so it never reveals whether an account exists (orthogonal to the
 * normalised 401).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final readonly class LoginOriginListener
{
    private const string LOGIN_ROUTE = 'identity_login';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (self::LOGIN_ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        if ($request->headers->get('Origin') === $request->getSchemeAndHttpHost()) {
            return;
        }

        throw new AccessDeniedHttpException('Cross-origin request rejected.');
    }
}
