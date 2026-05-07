<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Story 4.6 — test-only failure injector for the `/api/v1/backoffice/health` and
 * `/api/v1/health` endpoints. Wired exclusively when `APP_ENV=test` via
 * `api/config/services_test.yaml`; production code is NOT touched.
 *
 * The subscriber listens on `kernel.controller`. When the request matches one of the
 * two health paths AND carries the `_force_failure` query parameter, it throws a plain
 * {@see RuntimeException} BEFORE the controller runs. The exception escapes back into
 * Symfony's kernel, where {@see \Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder}
 * catches it and emits a conforming RFC 9457 Problem Details 500 response.
 *
 * This proves the contract applies retroactively to endpoints whose author never knew
 * it existed (FR11): the `HealthController` classes themselves are unchanged — the
 * conformance is purely a property of the listener pipeline.
 *
 * @internal
 */
final class ForceHealthFailureSubscriber implements EventSubscriberInterface
{
    /**
     * The two paths advertised by the routing layer (see `api/config/routes.yaml`):
     *   - `/api/v1/backoffice/health` (route name: `backoffice_health`)
     *   - `/api/v1/health`            (route name: `frontoffice_health`)
     *
     * These are the endpoints Story 4.6 calls out as the "Amelia-free validation"
     * targets.
     *
     * @var list<string>
     */
    private const array HEALTH_PATHS = [
        '/api/v1/backoffice/health',
        '/api/v1/health',
    ];

    public const string FORCE_FAILURE_QUERY_PARAM = '_force_failure';

    public const string FAILURE_MESSAGE = 'Health probe failure injected for Story 4.6 conformance test.';

    /**
     * @return array<string, array<int, string|int>>
     */
    public static function getSubscribedEvents(): array
    {
        // Priority 0 — anywhere on `kernel.controller` is fine; the listener pipeline
        // is exception-only so the throw simply unwinds back through HttpKernel.
        return [KernelEvents::CONTROLLER => ['onController', 0]];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        if (!\in_array($request->getPathInfo(), self::HEALTH_PATHS, true)) {
            return;
        }

        if (!$request->query->has(self::FORCE_FAILURE_QUERY_PARAM)) {
            return;
        }

        throw new RuntimeException(self::FAILURE_MESSAGE);
    }
}
