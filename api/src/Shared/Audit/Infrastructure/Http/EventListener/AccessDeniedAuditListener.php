<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Http\EventListener;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/**
 * Records a permission denial as a `security` audit entry by hooking the existing RFC 9457 exception
 * pipeline, rather than scattering audit calls through the handlers. On `kernel.exception` it looks for
 * a Security-Core {@see AccessDeniedException} anywhere in the throwable chain (a firewall may wrap it)
 * and, when an `/api` main request raised one, emits `ACCESS_DENIED` at {@see AuditLevel::SECURITY}
 * through the {@see AuditLogger} seam — a synchronous write-before-send, so the denial survives even if
 * the process dies after the response.
 *
 * Priority > `ExceptionResponder::PRIORITY` (16): {@see ExceptionEvent} extends `RequestEvent`, whose
 * `setResponse()` stops propagation, so a listener after the Problem Details responder never sees the
 * throwable. This one runs first, only reads, and never sets a response — the 403 body is left
 * untouched. The one exception is durability: a failed `security` write propagates by design rather
 * than letting a denial complete unrecorded, so it may surface as a 5xx.
 */
final readonly class AccessDeniedAuditListener
{
    public const int PRIORITY = 32;

    private const string ACTION = 'ACCESS_DENIED';

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

        if (!$this->isAccessDenied($event->getThrowable())) {
            return;
        }

        // The denied route is a forensic dimension, not part of the action: `action` stays the
        // cardinality-1 `ACCESS_DENIED` (so "all denials" remains an indexed equality, and dashboards
        // and alerts aggregate over it), while the route lives in `metadata` for the per-resource
        // drill-down an investigation actually runs.
        $this->auditLogger->log(self::ACTION, AuditLevel::SECURITY, metadata: ['route' => $this->routeOf($request)]);
    }

    private function routeOf(Request $request): ?string
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) ? $route : null;
    }

    private function isAccessDenied(Throwable $throwable): bool
    {
        // Cycle-safe walk (spl_object_id seen-set): a malformed/rewrapped chain with a reused previous
        // instance MUST NOT loop and stall the FrankenPHP worker — the guard ProblemDetailsFactory and
        // UnauthenticatedAccessListener also apply.
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
