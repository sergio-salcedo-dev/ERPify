<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Application\Projection\BankCountReadModel;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Event\Application\ProjectionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the "the bus delivers the same event twice" scenario: one `BankCreatedDomainEvent`
 * processed through the live pipeline twice must move `bank_count` by exactly `+1`, never `+2`.
 *
 * The `+1/−1` counter is not naturally idempotent, so the guarantee comes from two layers acting
 * together — the same two a redelivery exercises in production: the `event_store` UNIQUE on `event_id`
 * collapses the duplicate append to one row, and the projection checkpoint in {@see ProjectionRunner}
 * refuses to re-apply a `sequence` it has already passed. This asserts the *combination* end to end,
 * where {@see \Erpify\Tests\Functional\Shared\Persistence\DomainEventStoreIdempotencyTest} and
 * {@see \Erpify\Tests\Functional\Shared\Persistence\ProjectionCheckpointStoreFunctionalTest} each cover
 * one layer in isolation.
 *
 * Runs inside a transaction that is always rolled back: the checkpoint is brought to head first so the
 * appended event is the only delta, the total is asserted against that baseline, and rollback restores
 * the real `bank_count`, its checkpoint and the appended row on the shared dev database.
 *
 * @internal
 */
#[CoversClass(ProjectionRunner::class)]
final class BankCountDuplicateDeliveryFunctionalTest extends KernelTestCase
{
    private const string PROJECTION = 'bank_count';

    public function testDuplicateDeliveryOfTheSameEventIncrementsTheCounterOnce(): void
    {
        self::bootKernel();

        $eventStore = self::getContainer()->get(EventStore::class);
        $this->assertInstanceOf(EventStore::class, $eventStore);

        $projectionRunner = self::getContainer()->get(ProjectionRunner::class);
        $this->assertInstanceOf(ProjectionRunner::class, $projectionRunner);

        $readModel = self::getContainer()->get(BankCountReadModel::class);
        $this->assertInstanceOf(BankCountReadModel::class, $readModel);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // Bring the projection to head so the appended event below is the only unprocessed delta.
            $projectionRunner->catchUp(self::PROJECTION);
            $baseline = $readModel->total();

            $event = $this->bankCreated();

            // Two deliveries of the same event: the persist middleware re-runs on the redelivered
            // message, so the append happens twice — the second is a silent no-op on the UNIQUE event_id.
            $eventStore->append($event);
            $eventStore->append($event);

            // Each delivery fires projection catch-up. The first applies the event; the second finds the
            // checkpoint already past its sequence and applies nothing.
            $projectionRunner->catchUp(self::PROJECTION);
            $this->assertSame($baseline + 1, $readModel->total(), 'the first delivery increments the counter once');

            $projectionRunner->catchUp(self::PROJECTION);
            $this->assertSame($baseline + 1, $readModel->total(), 'the duplicate delivery is a no-op, not a second +1');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function bankCreated(): BankCreatedDomainEvent
    {
        $now = '2026-06-06T00:00:00+00:00';
        $snapshot = new BankSnapshot('Duplicate Delivery Bank', 'DUP', $now, $now);

        return new BankCreatedDomainEvent(Uuid::generate(), $snapshot);
    }
}
