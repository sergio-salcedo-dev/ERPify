<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Bus\Event;

use Erpify\Shared\Domain\Bus\Event\EventBus;
use Erpify\Shared\Domain\Event\DomainEvent;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
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

    #[Override]
    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->messageBus->dispatch($event);
        }
    }
}
