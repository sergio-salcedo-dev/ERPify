<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use Erpify\Shared\Event\Application\ProjectionRunner;
use Erpify\Shared\Event\Domain\DomainEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Live trigger for projection catch-up: after the worker delivers any domain event, run every
 * projector forward from its checkpoint. The handler is intentionally dumb — correctness (ordering,
 * exactly-once) comes from the checkpoint in {@see ProjectionRunner}, not from this firing. It reads
 * the permanent `event_store`, never the delivered message, so a missed or duplicated delivery cannot
 * corrupt a read model (the next run reconciles).
 *
 * The handled type is declared via `handles:` (rather than an unused `__invoke(DomainEvent)` parameter
 * a dead-code fixer would strip); Messenger routes every {@see DomainEvent} subclass to it.
 */
#[AsMessageHandler(handles: DomainEvent::class)]
final readonly class RunProjectionsOnDomainEvent
{
    public function __construct(private ProjectionRunner $projectionRunner)
    {
    }

    public function __invoke(): void
    {
        $this->projectionRunner->catchUpAll();
    }
}
