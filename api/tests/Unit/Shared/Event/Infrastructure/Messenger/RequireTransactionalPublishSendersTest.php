<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Infrastructure\Messenger\MessageRoutedOffTransactionalTransport;
use Erpify\Shared\Event\Infrastructure\Messenger\PublishedOutsideTransaction;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Every sender of a guarded message — a `DomainEvent`, or anything routed to `async` — must be one of the two
 * admitted: `async`, validated as the Doctrine transport on the caller's connection, or `sync`, which dispatches
 * again in process. The set is closed by name: `failed` is Doctrine on the same connection and is still refused.
 * Any other sender, alone or beside an admitted one, is refused before the next middleware runs; a guarded
 * message with no sender at all is handled in process and passes. Routing goes through Messenger's real senders
 * locator, as in {@see RequireTransactionalPublishRoutingTest}.
 *
 * @internal
 */
#[CoversClass(RequireTransactionalPublishMiddleware::class)]
#[CoversClass(MessageRoutedOffTransactionalTransport::class)]
final class RequireTransactionalPublishSendersTest extends TestCase
{
    use BuildsPublishGuard;

    #[Test]
    public function aDomainEventRoutedOnlyToAnotherAliasIsRefusedInsideATransaction(): void
    {
        $this->expectException(MessageRoutedOffTransactionalTransport::class);
        $this->expectExceptionMessageIsOrContains('routed to failed,');

        $this->middlewareInTransactionRoutedBy([DomainEvent::class => ['failed']])
            ->handle(new Envelope($this->createStub(DomainEvent::class)), $this->refusingStack())
        ;
    }

    #[Test]
    public function everyAliasOutsideTheAdmittedSetIsNamed(): void
    {
        $this->expectException(MessageRoutedOffTransactionalTransport::class);
        $this->expectExceptionMessageIsOrContains('routed to failed, broker,');

        $this->middlewareInTransactionRoutedBy([DomainEvent::class => ['async', 'failed', 'broker']])
            ->handle(new Envelope($this->createStub(DomainEvent::class)), $this->refusingStack())
        ;
    }

    #[Test]
    public function anAliasBesideSyncIsRefusedAndNamed(): void
    {
        $this->expectException(MessageRoutedOffTransactionalTransport::class);
        $this->expectExceptionMessageIsOrContains('routed to broker,');

        $this->middlewareInTransactionRoutedBy([DomainEvent::class => ['sync', 'broker']])
            ->handle(new Envelope($this->createStub(DomainEvent::class)), $this->refusingStack())
        ;
    }

    #[Test]
    public function aDomainEventRoutedOnlyToSyncPassesInsideATransaction(): void
    {
        $envelope = new Envelope($this->createStub(DomainEvent::class));

        $result = $this->middlewareInTransactionRoutedBy([DomainEvent::class => ['sync']])
            ->handle($envelope, $this->passThroughStack())
        ;

        $this->assertSame($envelope, $result);
    }

    #[Test]
    public function aDomainEventRoutedToAsyncAndSyncPassesInsideATransaction(): void
    {
        $envelope = new Envelope($this->createStub(DomainEvent::class));
        $connection = $this->connection(inTransaction: true);

        $result = $this->guardRoutedBy(
            [DomainEvent::class => ['async', 'sync']],
            $connection,
            $this->doctrineTransportOn($connection),
        )->handle($envelope, $this->passThroughStack());

        $this->assertSame($envelope, $result);
    }

    #[Test]
    public function aMessageRoutedToAsyncAndAnotherAliasIsRefusedNamingTheOther(): void
    {
        $this->expectException(MessageRoutedOffTransactionalTransport::class);
        $this->expectExceptionMessageIsOrContains('routed to failed,');

        $this->middlewareInTransactionRoutedBy([stdClass::class => ['async', 'failed']])
            ->handle(new Envelope(new stdClass()), $this->refusingStack())
        ;
    }

    #[Test]
    public function anEnvelopeAddressedByStampToAsyncAndAnotherAliasIsRefused(): void
    {
        $this->expectException(MessageRoutedOffTransactionalTransport::class);
        $this->expectExceptionMessageIsOrContains('routed to failed,');

        $this->middlewareInTransactionRoutedBy([])
            ->handle(
                new Envelope($this->createStub(DomainEvent::class), [new TransportNamesStamp(['async', 'failed'])]),
                $this->refusingStack(),
            )
        ;
    }

    #[Test]
    public function aDomainEventRoutedOffAsyncOutsideATransactionIsRefusedForTheTransactionFirst(): void
    {
        $this->expectException(PublishedOutsideTransaction::class);

        $this->middlewareRoutedBy([DomainEvent::class => ['failed']])
            ->handle(new Envelope($this->createStub(DomainEvent::class)), $this->refusingStack())
        ;
    }

    #[Test]
    public function aDomainEventRoutedOnlyToAsyncPassesInsideATransaction(): void
    {
        $envelope = new Envelope($this->createStub(DomainEvent::class));
        $connection = $this->connection(inTransaction: true);

        $result = $this->guardRoutedBy(
            [DomainEvent::class => ['async']],
            $connection,
            $this->doctrineTransportOn($connection),
        )->handle($envelope, $this->passThroughStack());

        $this->assertSame($envelope, $result);
    }

    #[Test]
    public function anUnroutedDomainEventPassesInsideATransaction(): void
    {
        $envelope = new Envelope($this->createStub(DomainEvent::class));

        $result = $this->middlewareInTransactionRoutedBy([stdClass::class => ['failed']])
            ->handle($envelope, $this->passThroughStack())
        ;

        $this->assertSame($envelope, $result);
    }

    /**
     * @param array<string, list<string>> $sendersMap
     */
    private function middlewareRoutedBy(array $sendersMap): RequireTransactionalPublishMiddleware
    {
        return $this->guardRoutedBy($sendersMap, $this->connection(inTransaction: false), new InMemoryTransport());
    }

    /**
     * @param array<string, list<string>> $sendersMap
     */
    private function middlewareInTransactionRoutedBy(array $sendersMap): RequireTransactionalPublishMiddleware
    {
        return $this->guardRoutedBy($sendersMap, $this->connection(inTransaction: true), new InMemoryTransport());
    }
}
