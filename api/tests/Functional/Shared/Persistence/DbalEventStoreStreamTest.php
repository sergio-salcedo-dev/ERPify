<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Application\StoredEvent;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reads back an appended event through {@see DbalEventStore::stream()} and asserts the full
 * {@see StoredEvent} mapping (sequence ordering, payload decode, envelope columns). Runs inside a
 * transaction that is always rolled back, so it leaves no rows behind on the shared dev database.
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
            $bankId = Uuid::generate();
            $occurredOn = '2026-06-06T08:00:00+00:00';
            $snapshot = new BankSnapshot('Stream Bank', 'STRM', $occurredOn, $occurredOn);
            $event = new BankCreatedDomainEvent($bankId, $snapshot);

            $store->append($event);
            $sequence = $this->sequenceOf($connection, $event->eventId());

            $streamed = $this->collect($store->stream($sequence - 1, [BankCreatedDomainEvent::eventName()], 10));
            $found = $this->locate($streamed, $event->eventId());

            $this->assertSame($sequence, $found->sequence);
            $this->assertSame($bankId, $found->aggregateId);
            $this->assertSame('Backoffice.Bank', $found->aggregateType);
            $this->assertSame(BankCreatedDomainEvent::eventName(), $found->eventName);
            $this->assertSame(1, $found->eventVersion);
            $this->assertSame('Stream Bank', $found->payload['name'] ?? null);
            $this->assertSame('STRM', $found->payload['shortName'] ?? null);
            $this->assertNull($found->tenantId);

            $this->assertSame([], $this->collect($store->stream(0, [], 10)), 'an empty name filter streams nothing');
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
