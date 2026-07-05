<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Http\EventListener;

use Erpify\Backoffice\Audit\Infrastructure\Controller\AuditEventDetailController;
use Erpify\Backoffice\Audit\Infrastructure\Controller\AuditTimelineSearchController;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records every authorized read of the audit trail as its own `security` entry, so accessing the audit
 * log is itself auditable. It mirrors the sibling `AccessDeniedAuditListener` on the success path: on
 * `kernel.response`, for a 2xx main-request read of one of the two audit routes, it emits
 * `AUDIT_TRAIL_READ` at {@see AuditLevel::SECURITY} through the {@see AuditLogger} seam — a
 * synchronous write-before-send, so the access record survives even if the process dies after the
 * response. The actor, correlation id and instant are sealed by the adapter; this listener only names the
 * action and the route (plus the read event id on the detail route) as the forensic "who read what".
 *
 * It lives in `Backoffice/Audit` because it references its own module's two routes; the shared audit
 * policy deliberately holds no catalogue of concrete routes. Those routes carry `_audit_canonical`, so the
 * generic access-log hook yields and this stays the single, richer record of the read.
 *
 * `kernel.response`, not `kernel.terminate`: a `security` row must be written inside the request cycle,
 * before the response is sent, unlike the latency-free `activity` capture. No priority is needed — no
 * other response listener gates this one, and `isSuccessful()` leaves the 403 to the denial listener.
 */
final readonly class AuditTrailReadAuditListener
{
    private const string ACTION = 'AUDIT_TRAIL_READ';

    /** @var non-empty-list<string> */
    private const array READ_ROUTES = [
        AuditTimelineSearchController::ROUTE_NAME,
        AuditEventDetailController::ROUTE_NAME,
    ];

    public function __construct(
        private AuditLogger $auditLogger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getResponse()->isSuccessful()) {
            return;
        }

        $route = $this->routeOf($event->getRequest());

        if (null === $route || !\in_array($route, self::READ_ROUTES, true)) {
            return;
        }

        $this->auditLogger->log(
            self::ACTION,
            AuditLevel::SECURITY,
            metadata: $this->metadataFor($event->getRequest(), $route),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFor(Request $request, string $route): array
    {
        if (AuditEventDetailController::ROUTE_NAME !== $route) {
            return ['route' => $route];
        }

        // On the by-id detail route the "what" is the specific event read, so it joins the route as a
        // forensic dimension in metadata — never as a linked `resource`, which would point an audit row at
        // another audit row (the recursive noise the detail route already avoids).
        return ['route' => $route, 'auditEventId' => $this->readEventIdOf($request)];
    }

    private function routeOf(Request $request): ?string
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) ? $route : null;
    }

    private function readEventIdOf(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $id : null;
    }
}
