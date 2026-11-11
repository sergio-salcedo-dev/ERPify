<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Infrastructure\Messenger\PublishedOutsideTransaction;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Which dispatches are publications that need a transaction, and which pass untouched. The transport half of
 * the guard is {@see RequireTransactionalPublishTransportTest}.
 *
 * @internal
 */
#[CoversClass(RequireTransactionalPublishMiddleware::class)]
#[CoversClass(PublishedOutsideTransaction::class)]
final class RequireTransactionalPublishMiddlewareTest extends TestCase
{
    use BuildsPublishGuard;

    #[Test]
    public function itLetsARoutedEventThroughInsideATransactionOnTheDoctrineTransport(): void
    {
        $connection = $this->connection(inTransaction: true);
        $envelope = new Envelope($this->createStub(DomainEvent::class));

        $result = $this->middleware($connection, true, $this->doctrineTransportOn($connection))
            ->handle($envelope, $this->passThroughStack())
        ;

        $this->assertSame($envelope, $result);
    }

    #[Test]
    public function itRefusesARoutedEventOutsideATransactionBeforeAnythingIsWritten(): void
    {
        $connection = $this->connection(inTransaction: false);
        $event = $this->createStub(DomainEvent::class);

        $this->expectException(PublishedOutsideTransaction::class);
        $this->expectExceptionMessageIsOrContains($event::class);

        $this->middleware($connection, true, $this->doctrineTransportOn($connection))
            ->handle(new Envelope($event), $this->refusingStack())
        ;
    }

    #[Test]
    public function itRefusesAnUnroutedDomainEventOutsideATransaction(): void
    {
        $this->expectException(PublishedOutsideTransaction::class);

        $this->middleware($this->connection(inTransaction: false), false, new InMemoryTransport())
            ->handle(new Envelope($this->createStub(DomainEvent::class)), $this->refusingStack())
        ;
    }

    #[Test]
    public function itLetsAnUnroutedDomainEventThroughInsideATransactionWhateverAsyncIs(): void
    {
        $envelope = new Envelope($this->createStub(DomainEvent::class));

        $result = $this->middleware($this->connection(inTransaction: true), false, new InMemoryTransport())
            ->handle($envelope, $this->passThroughStack())
        ;

        $this->assertSame($envelope, $result);
    }

    #[Test]
    public function itPassesAReceivedEnvelopeWithoutChecking(): void
    {
        // A worker delivery or a failed:retry: no transaction, a wrong transport, and still not a publication.
        $envelope = new Envelope($this->createStub(DomainEvent::class), [new ReceivedStamp('async')]);

        $result = $this->middleware($this->connection(inTransaction: false), true, new InMemoryTransport())
            ->handle($envelope, $this->passThroughStack())
        ;

        $this->assertSame($envelope, $result);
    }

    #[Test]
    public function itPassesAnUnroutedMessageThatIsNotADomainEventOutsideATransaction(): void
    {
        $envelope = new Envelope(new stdClass());

        $result = $this->middleware($this->connection(inTransaction: false), false, new InMemoryTransport())
            ->handle($envelope, $this->passThroughStack())
        ;

        $this->assertSame($envelope, $result);
    }

    #[Test]
    public function itRefusesAMessageThatIsNotADomainEventWhenItIsRoutedToAsyncOutsideATransaction(): void
    {
        $this->expectException(PublishedOutsideTransaction::class);
        $this->expectExceptionMessageIsOrContains(stdClass::class);

        $this->middleware($this->connection(inTransaction: false), true, new InMemoryTransport())
            ->handle(new Envelope(new stdClass()), $this->refusingStack());
    }
}
