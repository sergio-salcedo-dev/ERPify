<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Throwable;

/**
 * Refuses to publish what must commit atomically with the business write when it would not.
 *
 * Two facts make `event_store` and the outbox atomic with the aggregate: the publication happens inside a
 * transaction, and `async` is the Doctrine transport on that transaction's connection. This middleware checks
 * both at the point of publication, for every original dispatch:
 *
 *  - a {@see DomainEvent}, or any message Messenger routes to `async`, needs a transaction active on the ORM
 *    connection — {@see PublishedOutsideTransaction};
 *  - a message routed to `async` also needs `async` to be the transactional transport —
 *    {@see AsyncTransportNotTransactional}.
 *
 * It must precede {@see PersistDomainEventMiddleware}: that middleware appends to `event_store` on whatever
 * connection state it finds, so a publication outside a transaction would autocommit a row before the refusal.
 * A received envelope (worker, retry, `messenger:failed:retry`) is a delivery, not a publication, and passes
 * untouched.
 *
 * "Routed to `async`" is read from Messenger's own senders locator, so routing by class, interface, wildcard
 * or `TransportNamesStamp` is honoured without a list kept here. `isTransactionActive()` proves a transaction
 * is open on the connection, not that it is the one carrying the business write.
 */
final readonly class RequireTransactionalPublishMiddleware implements MiddlewareInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'messenger.senders_locator')]
        private SendersLocatorInterface $sendersLocator,
        private AsyncTransportValidator $asyncTransport,
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->last(ReceivedStamp::class) instanceof StampInterface) {
            $this->guard($envelope);
        }

        return $stack->next()->handle($envelope, $stack);
    }

    private function guard(Envelope $envelope): void
    {
        $messageClass = $envelope->getMessage()::class;
        $routedToAsync = $this->isRoutedToAsync($envelope, $messageClass);

        if (!$routedToAsync && !$envelope->getMessage() instanceof DomainEvent) {
            return;
        }

        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new PublishedOutsideTransaction($messageClass);
        }

        if (!$routedToAsync) {
            return;
        }

        $problem = $this->asyncTransport->problem();

        if (null !== $problem) {
            throw new AsyncTransportNotTransactional($messageClass, $problem);
        }
    }

    private function isRoutedToAsync(Envelope $envelope, string $messageClass): bool
    {
        try {
            $senders = $this->sendersLocator->getSenders($envelope);

            return \array_key_exists(AsyncTransportValidator::TRANSPORT, \iterator_to_array($senders));
        } catch (Throwable $throwable) {
            // Resolving a sender builds its transport. The class only, for the reason the validator gives:
            // a factory's message can quote a DSN.
            throw new AsyncTransportNotTransactional(
                $messageClass,
                \sprintf('A transport it is routed to cannot be built (%s).', $throwable::class),
            );
        }
    }
}
