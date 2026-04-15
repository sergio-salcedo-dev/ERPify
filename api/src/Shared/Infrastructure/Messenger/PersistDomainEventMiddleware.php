<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Messenger;

use Erpify\Shared\Application\DomainEvent\DomainEventStore;
use Erpify\Shared\Domain\Event\DomainEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Runs before {@see \Symfony\Component\Messenger\Middleware\SendMessageMiddleware} so audit rows
 * exist even if enqueue fails.
 */
final readonly class PersistDomainEventMiddleware implements MiddlewareInterface
{
    public function __construct(private DomainEventStore $domainEventStore)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if ($message instanceof DomainEvent) {
            $this->domainEventStore->append($message);
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
