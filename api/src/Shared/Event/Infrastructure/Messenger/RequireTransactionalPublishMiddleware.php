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
 * both at the point of publication, for every original dispatch of a guarded message — a {@see DomainEvent},
 * or any message Messenger routes to `async` — in this order:
 *
 *  - every sender the message is routed to can be built: resolving one builds its transport, and a transport
 *    that cannot be built is refused first, as {@see AsyncTransportNotTransactional} naming the failure's class
 *    and never its message, which can quote a DSN;
 *  - a transaction is active on the ORM connection — {@see PublishedOutsideTransaction};
 *  - every transport the message is routed to is one of the two admitted: `async`, or `sync`, which dispatches
 *    the message again in process, inside the caller's transaction. Any other sender, alone or beside an
 *    admitted one, is refused — {@see MessageRoutedOffTransactionalTransport}. The set is closed by name, not
 *    by what a transport happens to be: `failed` is Doctrine on the same connection and is still refused,
 *    because it is the failure queue, not a routing destination. `sync` is trusted by its name, and that name
 *    is held to `sync://` at build time by `MessengerRoutingConfig::misdeclaredNonPersistedTransports()`
 *    (`PersistentTransportRoutingShapeGateTest`);
 *  - when it is routed to `async`, `async` is the transactional transport — {@see AsyncTransportNotTransactional}.
 *
 * A guarded message with no sender at all is valid: an unrouted domain event is handled in process, inside the
 * caller's transaction, on purpose.
 *
 * It must precede {@see PersistDomainEventMiddleware}: that middleware appends to `event_store` on whatever
 * connection state it finds, so a publication outside a transaction would autocommit a row before the refusal.
 * A received envelope (worker, retry, `messenger:failed:retry`) is a delivery, not a publication, and passes
 * untouched.
 *
 * The senders are read from Messenger's own senders locator, so routing by class, interface, wildcard or
 * `TransportNamesStamp` is honoured without a list kept here. `isTransactionActive()` proves a transaction is
 * open on the connection, not that it is the one carrying the business write.
 */
final readonly class RequireTransactionalPublishMiddleware implements MiddlewareInterface
{
    /**
     * The transports a guarded message may be sent to: `async`, which the validator then inspects, and `sync`,
     * which never leaves the process.
     */
    private const array ADMITTED_SENDERS = [AsyncTransportValidator::TRANSPORT, 'sync'];

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
        $aliases = $this->senderAliases($envelope, $messageClass);
        $routedToAsync = \in_array(AsyncTransportValidator::TRANSPORT, $aliases, true);

        if (!$routedToAsync && !$envelope->getMessage() instanceof DomainEvent) {
            return;
        }

        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new PublishedOutsideTransaction($messageClass);
        }

        $offTransport = \array_values(\array_diff($aliases, self::ADMITTED_SENDERS));

        if ([] !== $offTransport) {
            throw new MessageRoutedOffTransactionalTransport($messageClass, $offTransport);
        }

        if (!$routedToAsync) {
            return;
        }

        $problem = $this->asyncTransport->problem();

        if (null !== $problem) {
            throw new AsyncTransportNotTransactional($messageClass, $problem);
        }
    }

    /**
     * @return list<string> the alias of every transport the envelope would be sent to
     */
    private function senderAliases(Envelope $envelope, string $messageClass): array
    {
        try {
            $senders = \iterator_to_array($this->sendersLocator->getSenders($envelope));

            return \array_map(
                static fn (int|string $alias): string => (string) $alias,
                \array_keys($senders),
            );
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
