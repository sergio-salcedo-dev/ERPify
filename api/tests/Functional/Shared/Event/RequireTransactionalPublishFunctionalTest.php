<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Event\Infrastructure\Messenger\MessageRoutedOffTransactionalTransport;
use Erpify\Shared\Event\Infrastructure\Messenger\PublishedOutsideTransaction;
use Erpify\Shared\Event\Infrastructure\Messenger\RequireTransactionalPublishMiddleware;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The guard's position in the default bus, pinned by behaviour: published outside a transaction, a routed
 * event leaves NOTHING behind — not the `event_store` row `PersistDomainEventMiddleware` would have
 * autocommitted had it run first, and not a message in the `async` transport. Reordering the middleware list
 * turns the first case red. Inside a transaction, an event addressed to a transport outside the admitted set
 * is refused on the same bus before the append, so it too leaves no `event_store` row, while one addressed to
 * `sync` passes and is stored once.
 *
 * `async` is the in-memory double here by `when@test`, so the outbox table never receives rows in this
 * environment and is not asserted: what is pinned is the `event_store` row count and the double's
 * `getSent()`. The Doctrine transport's own insert is observed by `AsyncDoctrineTransportFunctionalTest`.
 *
 * @internal
 */
#[CoversClass(RequireTransactionalPublishMiddleware::class)]
#[CoversClass(MessageRoutedOffTransactionalTransport::class)]
final class RequireTransactionalPublishFunctionalTest extends KernelTestCase
{
    #[Test]
    public function aRoutedEventPublishedOutsideATransactionWritesNothing(): void
    {
        $connection = $this->connection();
        $event = new ImageDeletionRequested(Uuid::generate());
        $eventStoreBefore = $this->eventStoreRows($connection);

        try {
            $this->eventBus()->publish($event);
            $this->fail('A publication outside a transaction must be refused.');
        } catch (PublishedOutsideTransaction $publishedOutsideTransaction) {
            $this->assertStringContainsString(
                ImageDeletionRequested::class,
                $publishedOutsideTransaction->getMessage(),
            );
        }

        $this->assertSame(0, $this->eventStoreRowsFor($connection, $event->eventId()));
        $this->assertSame($eventStoreBefore, $this->eventStoreRows($connection));
        $this->assertSame([], $this->asyncTransport()->getSent());
    }

    #[Test]
    public function aRoutedEventPublishedInsideATransactionIsStoredAndQueued(): void
    {
        $connection = $this->connection();
        $event = new ImageDeletionRequested(Uuid::generate());

        $connection->beginTransaction();

        try {
            $this->eventBus()->publish($event);

            $this->assertSame(1, $this->eventStoreRowsFor($connection, $event->eventId()));
            $this->assertCount(1, $this->asyncTransport()->getSent());
        } finally {
            $connection->rollBack();
        }

        $this->assertSame(0, $this->eventStoreRowsFor($connection, $event->eventId()));
    }

    #[Test]
    public function anEventAddressedOffTheAdmittedTransportsInsideATransactionWritesNothing(): void
    {
        $connection = $this->connection();
        $event = new ImageDeletionRequested(Uuid::generate());

        $connection->beginTransaction();

        try {
            try {
                $this->messageBus()->dispatch(new Envelope($event, [new TransportNamesStamp(['failed'])]));
                $this->fail('A publication routed to a transport outside the admitted set must be refused.');
            } catch (MessageRoutedOffTransactionalTransport $routedOff) {
                $this->assertStringContainsString(ImageDeletionRequested::class, $routedOff->getMessage());
                $this->assertStringContainsString('routed to failed,', $routedOff->getMessage());
            }

            $this->assertSame(0, $this->eventStoreRowsFor($connection, $event->eventId()));
            $this->assertSame([], $this->inMemoryTransport('failed')->getSent());
        } finally {
            $connection->rollBack();
        }
    }

    #[Test]
    public function anEventAddressedToSyncInsideATransactionIsStoredOnce(): void
    {
        $connection = $this->connection();
        $event = new ImageDeletionRequested(Uuid::generate());

        $connection->beginTransaction();

        try {
            $this->messageBus()->dispatch(new Envelope($event, [new TransportNamesStamp(['sync'])]));

            $this->assertSame(1, $this->eventStoreRowsFor($connection, $event->eventId()));
            $this->assertSame([], $this->asyncTransport()->getSent());
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * The container's default bus itself, not the `EventBus` port: the port has no way to address a transport,
     * and the case needs a `TransportNamesStamp` on the envelope.
     */
    private function messageBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('messenger.bus.default');
        $this->assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function eventBus(): EventBus
    {
        $eventBus = self::getContainer()->get(EventBus::class);
        $this->assertInstanceOf(EventBus::class, $eventBus);

        return $eventBus;
    }

    private function asyncTransport(): InMemoryTransport
    {
        return $this->inMemoryTransport('async');
    }

    private function inMemoryTransport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.' . $name);
        $this->assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function connection(): Connection
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $this->assertFalse($connection->isTransactionActive(), 'the case starts outside any transaction');

        return $connection;
    }

    private function eventStoreRows(Connection $connection): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM event_store');
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function eventStoreRowsFor(Connection $connection, string $eventId): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM event_store WHERE event_id = :id', ['id' => $eventId]);
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
