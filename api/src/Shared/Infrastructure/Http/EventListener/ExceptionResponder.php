<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\EventListener;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Converts uncaught `/api/*` exceptions into RFC 9457 Problem Details responses by delegating
 * marker→status resolution to {@see ProblemDetailsFactory} and wire-envelope construction to
 * {@see ProblemDetailsResponder}.
 *
 * Path-scoped: only acts on requests whose path starts with `/api/` so the default Symfony
 * exception path stays intact for `/_profiler`, dev error pages, and `.well-known/...`.
 *
 * Coexists with earlier exception listeners (e.g. {@see SearchExceptionListener} at priority 32):
 * if a higher-priority listener has already set a response, this listener leaves it alone.
 *
 * Priority pinned by Story 4.1 (FR42, FR43). Logging joins in Story 2.4 (FR32, FR33). The
 * top-level try/catch fallback is added by Story 3.4 (FR39) — this story keeps the listener
 * simple because {@see ProblemDetailsFactory::fromThrowable()} is contractually total.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ExceptionResponder
{
    public function __construct(
        private ProblemDetailsFactory $problemDetailsFactory,
        private ProblemDetailsResponder $problemDetailsResponder,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();

        if (!\str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $correlationId = $request->attributes->get('correlation-id');

        if (!\is_string($correlationId)) {
            // TODO(story-2.1): remove fallback once the correlation-id request listener lands.
            $correlationId = Uuid::v7()->toRfc4122();
        }

        // TODO(story-2.3): instance minting may move to a dedicated helper in Epic 2.
        $instance = Uuid::v7()->toRfc4122();

        $problemDetails = $this->problemDetailsFactory->fromThrowable(
            $event->getThrowable(),
            $correlationId,
            $instance,
        );

        $event->setResponse($this->problemDetailsResponder->respond($problemDetails));
    }
}
