<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records a rejected current password as a `security` audit entry, closing the one credential-guessing
 * surface that left no trace at all: {@see \Erpify\Shared\Audit\Domain\AuditPolicy} audits `GET` only, so the
 * 204 of a successful change writes nothing generic, and {@see InvalidCurrentPassword} deliberately carries no
 * `AccessDeniedException` in its chain, so the sibling
 * {@see \Erpify\Shared\Audit\Infrastructure\Http\EventListener\AccessDeniedAuditListener} skips the 403 it
 * produces. Without this a sustained guessing run against any of the three routes that re-prove the current
 * password — `POST /me/password`, `POST /me/recovery-secret` and `POST /me/recovery-secret/revoke` — is
 * invisible to the trail. The listener matches on the throwable rather than on a route, so a fourth such
 * route is covered the day it exists, and the route itself travels in the row's metadata.
 *
 * The row is deliberately RESOURCE-LESS. The only candidate resource is the caller's own identity, and naming
 * it would write `actor_id == resource_id` by construction — a row that is its own subject, on the
 * `audit_log.resource_id` person axis, for no forensic gain the sealed `actor_id` does not already carry.
 * `metadata` holds the route and nothing else: the submitted password is never in scope here (it never leaves
 * the controller), and the identity is already sealed into `actor_id` by the adapter.
 *
 * It hooks `kernel.exception` at the sibling's {@see PRIORITY} rather than instrumenting the use case, for the
 * same two reasons: an {@see ExceptionEvent} extends `RequestEvent`, whose `setResponse()` stops propagation,
 * so a listener ordered after the Problem Details responder (priority 16) would never see the throwable; and
 * keeping the audit call out of the handler is what stops audit concerns scattering through the domain. This
 * listener only reads and never sets a response, so the 403 body is untouched. A failed `security` write
 * propagates by design — a denial is never lost in silence, at the price of surfacing as a 5xx.
 *
 * The throwable is matched directly rather than walked down the `previous` chain: unlike the firewall's
 * `AccessDeniedException`, nothing wraps this one — it travels from the use case to the responder untouched.
 *
 * The write is only meaningful behind {@see \Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle}:
 * a synchronous, per-attempt row on an unbudgeted endpoint is a write amplifier handed to the attacker it is
 * meant to record.
 */
final readonly class InvalidCurrentPasswordAuditListener
{
    public const int PRIORITY = 32;

    private const string ACTION = 'INVALID_CURRENT_PASSWORD';

    public function __construct(
        private AuditLogger $auditLogger,
        private ApiRequestMatcher $apiRequestMatcher,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->apiRequestMatcher->matches($request)) {
            return;
        }

        if (!$event->getThrowable() instanceof InvalidCurrentPassword) {
            return;
        }

        $this->auditLogger->log(self::ACTION, AuditLevel::SECURITY, metadata: ['route' => $this->routeOf($request)]);
    }

    private function routeOf(Request $request): ?string
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) ? $route : null;
    }
}
