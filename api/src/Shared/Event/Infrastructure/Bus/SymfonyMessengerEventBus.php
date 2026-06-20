<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Bus;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\EventBus;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * {@link EventBus} backed by Symfony Messenger's default bus. Sync-vs-async per event is decided by
 * messenger.yaml routing, not by separate bus classes. Registered as the autowired implementation
 * of {@see EventBus} via {@see AsAlias}.
 */
#[AsAlias(EventBus::class)]
final readonly class SymfonyMessengerEventBus implements EventBus
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    /**
     * Failure propagates by design: callers invoke this inside the use-case `wrapInTransaction`, so a
     * rejected dispatch aborts the aggregate save too (no dual write) and surfaces as an RFC 9457 500.
     * The framework exception type stays here — the {@see EventBus} port declares no throws, so
     * Application code never couples to it. See docs/adr/event-store-and-projections.md (D8).
     *
     * @throws ExceptionInterface transport send failure on the Doctrine outbox, or a sync-routed handler throwing
     */
    #[Override]
    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->messageBus->dispatch($event);
        }
    }
}
