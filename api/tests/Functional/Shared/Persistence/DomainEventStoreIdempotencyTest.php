<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the at-least-once redelivery guarantee: appending the same event twice writes
 * exactly one `event_store` row and never escapes an exception. The idempotency comes from the UNIQUE
 * on `event_id` plus the store's `INSERT … ON CONFLICT (event_id) DO NOTHING` — the second append is a
 * silent no-op that does not abort the surrounding transaction (the persist middleware re-runs when the
 * worker re-dispatches the message on consume).
 *
 * The work runs inside a transaction that is always rolled back (try/finally), so the test leaves no
 * rows behind — the suite has no DAMA auto-rollback and shares the dev database connection.
 *
 * @internal
 */
#[CoversClass(DbalEventStore::class)]
final class DomainEventStoreIdempotencyTest extends KernelTestCase
{
    public function testDoubleAppendOfTheSameEventWritesExactlyOneRow(): void
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
            $now = '2026-06-06T00:00:00+00:00';

            $event = new BankCreatedDomainEvent($bankId, new BankSnapshot('Idempotent Bank', 'IDEM', $now, $now));

            $store->append($event);
            $this->assertSame(
                1,
                $this->countRowsForEventId($connection, $event->eventId()),
                'first append must write exactly one row',
            );

            $store->append($event);
            $this->assertSame(
                1,
                $this->countRowsForEventId($connection, $event->eventId()),
                'second append must be a no-op, not a second insert nor a failed one',
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function countRowsForEventId(Connection $connection, string $eventId): int
    {
        $rowCount = $connection->fetchOne(
            'SELECT COUNT(*) FROM event_store WHERE event_id = :eventId',
            ['eventId' => $eventId],
        );
        $this->assertIsNumeric($rowCount);

        return (int) $rowCount;
    }
}
