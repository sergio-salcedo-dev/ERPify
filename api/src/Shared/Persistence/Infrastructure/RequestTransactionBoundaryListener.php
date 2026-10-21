<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use const PHP_SAPI;

/**
 * Ends the request's claim on the database: whatever transaction the request opened and did not close is rolled
 * back, see {@see LeakedTransactionContainment}.
 *
 * **Three checks, because one boundary is not enough.** Before every other `kernel.terminate` listener, so the
 * writers that run there (`AccessLogAuditListener`, `RecoveryThrottleAuditListener`) do not write into the leaked
 * transaction and lose their rows with it. What ran DURING the request ran inside it: an `AuditLogger` row a use
 * case wrote is lost with the rollback, while a request-boundary security row is refused by
 * `RequestBoundarySecurityAudit`, which will not write inside an open transaction and answers 5xx instead. After
 * every other one, so a terminate listener that leaks is caught too. And, outside `test`, at the start of the next
 * request, because `kernel.terminate` does not run when the runtime fails before it: whatever reaches a new request
 * open is a leak.
 *
 * **The start check runs ABOVE the dev/test connection reset, the baseline is read below it.**
 * `DoctrineConnectionResetListener` closes the connection at its own priority on `kernel.request` in dev and test.
 * Checked after it, a carried-over leak would already be gone without a report, and `close()` would leave DBAL's
 * rollback-only flag set for this request's first commit. The baseline is read after it because that is the level
 * the request really starts from — which, on this lane, means a transaction a functional test opened around its
 * requests has already been closed by the reset, so the observed baseline is zero in practice. Outside `test` it is
 * zero by definition. Under `test`, a request whose `kernel.request` never reached this listener has no baseline,
 * and nothing is rolled back rather than guessing one.
 *
 * **It fails the test that leaked.** Under `test` in the CLI — the kernel browser, which is how both PHPUnit and
 * Behat send requests — the rollback is followed by a throw, so a leaking route turns its test red instead of
 * passing on a transaction nobody closed; the throw also stops the terminate writers below it, which only matters
 * to a test that is already failing. Anywhere else a throw on terminate would take the worker down after the
 * response is already sent, so it only logs.
 */
final class RequestTransactionBoundaryListener
{
    private ?int $baseline = null;

    private readonly bool $baselineIsObserved;

    public function __construct(
        private readonly LeakedTransactionContainment $containment,
        #[Autowire(param: 'kernel.environment')]
        string $environment,
    ) {
        $this->baselineIsObserved = 'test' === $environment;
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: DoctrineConnectionResetListener::PRIORITY + 1)]
    public function onRequestStart(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->baselineIsObserved) {
            return;
        }

        $this->containment->containAbove(0, [
            'boundary' => 'http_request_start',
            'method' => $event->getRequest()->getMethod(),
        ]);
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: DoctrineConnectionResetListener::PRIORITY - 1)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->baseline = $this->baselineIsObserved ? $this->containment->nestingLevel() : 0;
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: 1024)]
    public function onTerminate(TerminateEvent $event): void
    {
        $this->contain($event, 'http_request');
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -2048)]
    public function onTerminateEnd(TerminateEvent $event): void
    {
        try {
            $this->contain($event, 'http_terminate');
        } finally {
            $this->baseline = null;
        }
    }

    private function contain(TerminateEvent $event, string $boundary): void
    {
        $baseline = $this->baseline ?? ($this->baselineIsObserved ? null : 0);

        if (null === $baseline) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        $leaked = $this->containment->containAbove($baseline, [
            'boundary' => $boundary,
            'route' => \is_string($route) ? $route : null,
            'method' => $event->getRequest()->getMethod(),
        ]);

        if ($leaked > 0 && $this->baselineIsObserved && PHP_SAPI === 'cli') {
            throw new LogicException(\sprintf(
                'The request to route "%s" left %d database transaction level(s) open at %s; they were rolled back.',
                \is_string($route) ? $route : '(unrouted)',
                $leaked,
                $boundary,
            ));
        }
    }
}
