<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers 404 on both ingest routes while `documents.ingest_enabled` is false — before authentication runs, so
 * a disabled ingest looks the same to everyone. The flag is independent of `documents.read_enabled`, defaults
 * to false outside dev and test, and may only be set true in tracked production configuration together with a
 * valid restore proof for the declared backup mechanism (`DocumentsIngestProofGateTest`).
 *
 * Priority 16: after the router (32) has named the route, before the firewall (8) authenticates.
 */
final readonly class DocumentsIngestGate
{
    public const int PRIORITY = 16;

    public function __construct(
        #[Autowire(param: 'documents.ingest_enabled')]
        private bool $ingestEnabled,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
    public function onRequest(RequestEvent $event): void
    {
        if ($this->ingestEnabled || !$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (UploadRoutes::DECLARE === $route || UploadRoutes::CONTENT === $route) {
            throw new NotFoundHttpException();
        }
    }
}
