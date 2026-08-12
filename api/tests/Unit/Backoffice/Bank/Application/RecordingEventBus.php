<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use Override;

/**
 * Spy {@see EventBus} that captures every published event, so a test can assert which domain events
 * a use case publishes (and that none escapes when a write is rejected).
 *
 * Given the unit of work a use case runs under, it also records WHERE each publication happened. Counting
 * boundaries cannot answer that: a use case that publishes after its transaction returns opens exactly as
 * many as one that publishes inside, so the dual-write window this design closes can reopen with every
 * count still correct. {@see self::$publishedInsideUnitOfWork} is the observable that goes red instead.
 *
 * @internal
 */
final class RecordingEventBus implements EventBus
{
    /** @var list<DomainEvent> */
    public array $publishedEvents = [];

    /** @var list<bool> One entry per published event: was a unit of work open when it was published? */
    public array $publishedInsideUnitOfWork = [];

    public function __construct(private readonly ?ImmediateTransactionManager $boundary = null)
    {
    }

    #[Override]
    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->publishedEvents[] = $event;
            $this->publishedInsideUnitOfWork[] = $this->boundary?->isInUnitOfWork() ?? false;
        }
    }
}
