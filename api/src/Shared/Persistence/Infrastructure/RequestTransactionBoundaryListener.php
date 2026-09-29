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
 * Ends the request's claim on the database: whatever transaction the request opened and did not close is
 * rolled back on `kernel.terminate`, see {@see LeakedTransactionContainment}.
 *
 * **Before every other terminate listener.** `AccessLogAuditListener` and `RecoveryThrottleAuditListener`
 * write on `kernel.terminate` at the default priority; running after them would roll their rows back with the
 * leaked transaction, so the audit trail would lose exactly the requests that went wrong.
 *
 * **The baseline is read after the dev/test connection reset.** `DoctrineConnectionResetListener` closes the
 * connection at priority 256 on `kernel.request`, so the level read below it is the one the request really
 * starts from.
 *
 * **It fails the test that leaked, and only there.** Under `test` in the CLI — the kernel browser, where
 * `terminate()` runs inside `request()` — the rollback is followed by a throw, so the test that leaked goes red
 * instead of passing on a transaction nobody closed. Everywhere else, the Behat server included, a throw on
 * terminate would take the worker down after the response is already sent, so it only logs.
 */
final class RequestTransactionBoundaryListener
{
    private const int CONNECTION_RESET_PRIORITY = 256;

    private ?int $baseline = null;

    public function __construct(
        private readonly LeakedTransactionContainment $containment,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::CONNECTION_RESET_PRIORITY - 1)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->baseline = $this->containment->nestingLevel();
        }
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: 1024)]
    public function onTerminate(TerminateEvent $event): void
    {
        $baseline = $this->baseline ?? 0;
        $this->baseline = null;

        $route = $event->getRequest()->attributes->get('_route');
        $leaked = $this->containment->containAbove($baseline, [
            'boundary' => 'http_request',
            'route' => \is_string($route) ? $route : null,
            'method' => $event->getRequest()->getMethod(),
        ]);

        if ($leaked > 0 && 'test' === $this->environment && PHP_SAPI === 'cli') {
            throw new LogicException(\sprintf(
                'The request to route "%s" left %d database transaction level(s) open; they were rolled back.',
                \is_string($route) ? $route : '(unrouted)',
                $leaked,
            ));
        }
    }
}
