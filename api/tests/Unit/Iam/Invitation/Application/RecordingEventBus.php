<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\EventBus;
use Override;

/**
 * Spy {@see EventBus} capturing every published event, so a test can assert which events an invitation use case
 * emits — and that none escapes on a rejected accept. Kept local to the Invitation module's tests.
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
