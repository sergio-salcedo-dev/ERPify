<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Appends every dispatched {@see DomainEvent} to the permanent `event_store` before
 * {@see \Symfony\Component\Messenger\Middleware\SendMessageMiddleware} enqueues it — both inside the
 * use-case `wrapInTransaction`, so the log row and the outbox enqueue commit atomically with the
 * aggregate.
 */
final readonly class PersistDomainEventMiddleware implements MiddlewareInterface
{
    public function __construct(private EventStore $eventStore)
    {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if ($message instanceof DomainEvent) {
            $this->eventStore->append($message);
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
