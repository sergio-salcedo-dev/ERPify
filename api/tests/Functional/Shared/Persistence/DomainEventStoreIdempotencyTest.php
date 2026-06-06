<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Shared\Application\DomainEvent\DomainEventStore;
use Erpify\Shared\Domain\Uuid\Uuid;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the at-least-once redelivery guarantee: appending the same event twice writes
 * exactly one `domain_event` row and never escapes an exception. The idempotency comes from the
 * unique index on `event_id` plus the store's `INSERT … ON CONFLICT (event_id) DO NOTHING` — the
 * second append is a silent no-op that does not abort the surrounding transaction.
 *
 * The work runs inside a transaction that is always rolled back (try/finally), so the test leaves no
 * rows behind — the suite has no DAMA auto-rollback and shares the dev database connection.
 *
 * @internal
 */
#[CoversNothing]
final class DomainEventStoreIdempotencyTest extends KernelTestCase
{
    public function testDoubleAppendOfTheSameEventWritesExactlyOneRow(): void
    {
        self::bootKernel();
        $store = self::getContainer()->get(DomainEventStore::class);
        $this->assertInstanceOf(DomainEventStore::class, $store);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $bankId = Uuid::generate();
            $now = '2026-06-06T00:00:00+00:00';

            $event = new BankCreatedDomainEvent($bankId, 'Idempotent Bank', 'IDEM', $now, $now);

            $store->append($event);
            $store->append($event);

            $rowCount = $connection->fetchOne(
                'SELECT COUNT(*) FROM domain_event WHERE event_id = :eventId',
                ['eventId' => $event->eventId()],
            );
            $this->assertIsNumeric($rowCount);

            $this->assertSame(1, (int) $rowCount);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
