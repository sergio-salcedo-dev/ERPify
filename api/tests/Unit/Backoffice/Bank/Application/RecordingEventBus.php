<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\EventBus;
use Override;

/**
 * Spy {@see EventBus} that captures every published event, so a test can assert which domain events
 * a use case publishes (and that none escapes when a write is rejected).
 *
 * @internal
 */
final class RecordingEventBus implements EventBus
{
    /** @var list<DomainEvent> */
    public array $publishedEvents = [];

    #[Override]
    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->publishedEvents[] = $event;
        }
    }
}
