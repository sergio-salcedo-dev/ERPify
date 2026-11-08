<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Turns the dev profiler off for the evidence PUT. Its request collector reads the body again, whole, into
 * memory: a 100 MiB upload exhausted the 256M memory limit in `RequestDataCollector`. Dev only,
 * because only dev registers the profiler — the prod container must not know this class, which is what
 * `make php.lint.prod-container` checks.
 */
#[When(env: 'dev')]
final readonly class UploadProfilerGuard
{
    public function __construct(
        #[Autowire(service: 'profiler')]
        private Profiler $profiler,
    ) {
    }

    // Above the firewall's 8: a request the firewall ends first must not be profiled with its query.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
    public function onRequest(RequestEvent $event): void
    {
        if (UploadRoutes::CONTENT === $event->getRequest()->attributes->get('_route')) {
            $this->profiler->disable();
        }
    }
}
