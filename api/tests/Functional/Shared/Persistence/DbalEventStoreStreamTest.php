<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Shared\Event\Application\DomainEventSerializer;
use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Application\StoredEvent;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStore;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reads back an appended event through {@see DbalEventStore::stream()} and asserts the full
 * {@see StoredEvent} mapping (sequence ordering, payload decode, envelope columns) and the stored shape of
 * `metadata`, which is always a JSON object. Each test runs inside a transaction that is always rolled
 * back, so it leaves no rows behind on the shared dev database.
 *
 * @internal
 */
#[CoversClass(DbalEventStore::class)]
final class DbalEventStoreStreamTest extends KernelTestCase
{
    public function testStreamMapsAStoredRowBackToAStoredEvent(): void
    {
        self::bootKernel();
        $store = self::getContainer()->get(EventStore::class);
        $this->assertInstanceOf(EventStore::class, $store);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $aggregateId = Uuid::generate();
            $occurredOn = '2026-06-06T08:00:00+00:00';
            $snapshot = new BankSnapshot('Stream Bank', 'STRM', $occurredOn, $occurredOn);
            $event = new BankCreatedDomainEvent($aggregateId, $snapshot);

            $store->append($event);
            $sequence = $this->sequenceOf($connection, $event->eventId());

            $streamed = $this->collect($store->stream($sequence - 1, [BankCreatedDomainEvent::eventName()], 10));
            $found = $this->locate($streamed, $event->eventId());

            $this->assertSame($sequence, $found->sequence);
            $this->assertSame($aggregateId, $found->aggregateId);
            $this->assertSame('Backoffice.Bank', $found->aggregateType);
            $this->assertSame(BankCreatedDomainEvent::eventName(), $found->eventName);
            $this->assertSame(1, $found->eventVersion);
            $this->assertSame('Stream Bank', $found->payload['name'] ?? null);
            $this->assertSame('STRM', $found->payload['shortName'] ?? null);
            $this->assertNull($found->tenantId);
            $this->assertSame([], $found->metadata);
            $this->assertSame('{}', $this->metadataOf($connection, $event->eventId()), 'an empty envelope: {}');

            $this->assertSame([], $this->collect($store->stream(0, [], 10)), 'an empty name filter streams nothing');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    /**
     * The envelope is empty for every event the application writes today, so a non-empty one is handed to the
     * store through its own serializer port: what is asserted is that Postgres keeps an object with its keys.
     */
    public function testANonEmptyMetadataEnvelopeIsStoredAsAnObjectWithItsKeys(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $serializer = $this->createStub(DomainEventSerializer::class);
        $serializer->method('serialize')->willReturn([
            'payload' => ['name' => 'Envelope Bank'],
            'metadata' => ['correlationId' => 'c-1', 'causes' => ['e-0']],
        ]);
        $store = new DbalEventStore($connection, $serializer);

        $connection->beginTransaction();

        try {
            $occurredOn = '2026-06-06T08:00:00+00:00';
            $event = new BankCreatedDomainEvent(
                Uuid::generate(),
                new BankSnapshot('Envelope Bank', 'ENVL', $occurredOn, $occurredOn),
            );

            $store->append($event);

            $this->assertSame(
                '{"causes": ["e-0"], "correlationId": "c-1"}',
                $this->metadataOf($connection, $event->eventId()),
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    /**
     * @param iterable<StoredEvent> $stream
     *
     * @return list<StoredEvent>
     */
    private function collect(iterable $stream): array
    {
        $events = [];

        foreach ($stream as $storedEvent) {
            $events[] = $storedEvent;
        }

        return $events;
    }

    /**
     * @param list<StoredEvent> $events
     */
    private function locate(array $events, string $eventId): StoredEvent
    {
        foreach ($events as $event) {
            if ($event->eventId === $eventId) {
                return $event;
            }
        }

        $this->fail(\sprintf('The appended event "%s" was not streamed back.', $eventId));
    }

    /**
     * Rendered by Postgres (`::text`), so the assertion reads the stored JSONB rather than the driver's view.
     */
    private function metadataOf(Connection $connection, string $eventId): string
    {
        $metadata = $connection->fetchOne(
            'SELECT metadata::text FROM event_store WHERE event_id = :eventId',
            ['eventId' => $eventId],
        );
        $this->assertIsString($metadata);

        return $metadata;
    }

    private function sequenceOf(Connection $connection, string $eventId): int
    {
        $sequence = $connection->fetchOne(
            'SELECT sequence FROM event_store WHERE event_id = :eventId',
            ['eventId' => $eventId],
        );
        $this->assertIsNumeric($sequence);

        return (int) $sequence;
    }
}
