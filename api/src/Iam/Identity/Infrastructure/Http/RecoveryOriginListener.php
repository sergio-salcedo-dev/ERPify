<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses a cross-site POST to the credential-recovery endpoints — forgot-password, reset-password and the
 * recovery-secret redemption — the same-origin guard {@see LoginOriginListener} applies to login, keyed on
 * the three recovery routes. They attend the `json` `_format`
 * default regardless of `Content-Type`, so a cross-site `<form enctype="text/plain">` with a JSON-shaped body
 * would otherwise reach them as a CORS "simple request" (no preflight) and could spam reset requests or drive a
 * reset with an attacker-chosen body. Requiring the browser-set `Origin` to equal the request's own origin is
 * the primary CSRF control here; the stateless double-submit token is defence-in-depth, deferred to the surface
 * that introduces it. A browser always sends `Origin` on a POST, so a missing header is treated as cross-site.
 *
 * Priority 9 runs it after `RouterListener` (so `_route` resolves) and before the firewall; a rejection throws
 * so the shared RFC 9457 pipeline answers a 403 `forbidden`, before any state mutation.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final readonly class RecoveryOriginListener
{
    private const array RECOVERY_ROUTES = [
        'identity_forgot_password',
        'identity_reset_password',
        RedeemRecoverySecretController::ROUTE_NAME,
    ];

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!\in_array($request->attributes->get('_route'), self::RECOVERY_ROUTES, true)) {
            return;
        }

        if ($request->headers->get('Origin') === $request->getSchemeAndHttpHost()) {
            return;
        }

        throw new AccessDeniedHttpException('Cross-origin request rejected.');
    }
}
