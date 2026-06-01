<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Shared\Infrastructure\Uuid\SymfonyUuidGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * End-to-end lock for the aggregate id mismatch fix (AC1): after a real persist → flush → refetch
 * round-trip, a Bank's persisted primary key equals the id carried by its `BankCreatedDomainEvent`,
 * and both are UUID v7. This exercises through Doctrine's UnitOfWork the guarantee that
 * {@see \Erpify\Tests\Functional\Doctrine\IdentifiableAssignedIdentifierTest} pins at the metadata
 * level — that Doctrine no longer overwrites the app-assigned id at flush.
 *
 * The write runs inside a transaction that is always rolled back (try/finally), so the test leaves
 * no rows behind — the suite has no DAMA auto-rollback and shares the dev database connection.
 *
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankCreateEventIdMatchesPersistedPkTest extends KernelTestCase
{
    public function testPersistedBankPrimaryKeyEqualsCreateEventIdAndBothAreV7(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $id = SymfonyUuidGenerator::generate();
            $createEventId = SymfonyUuidGenerator::generate();

            // Suffix name/short-name with the id so the row is unique regardless of any seeded fixtures.
            $suffix = \strtoupper(\substr(\str_replace('-', '', $id), 0, 8));

            $bank = Bank::create($id, $createEventId, 'Round Trip Savings ' . $suffix, 'RTS' . $suffix);

            $events = $bank->pullDomainEvents();
            $this->assertCount(1, $events);
            $createEvent = $events[0];
            $this->assertInstanceOf(BankCreatedDomainEvent::class, $createEvent);
            $eventAggregateId = $createEvent->aggregateId();

            $entityManager->persist($bank);
            $entityManager->flush();
            $entityManager->clear();

            $persisted = $entityManager->find(Bank::class, $id);
            $this->assertInstanceOf(Bank::class, $persisted);

            $persistedId = $persisted->getId();
            $this->assertNotNull($persistedId);

            // Core invariant: Doctrine persisted the app-assigned id unchanged, so the PK read back
            // from the database equals both the id we assigned and the id the create event recorded.
            $this->assertSame($id, $persistedId);
            $this->assertSame($eventAggregateId, $persistedId);

            // …and the whole chain stays on UUID v7.
            $this->assertInstanceOf(UuidV7::class, Uuid::fromString($persistedId));
            $this->assertInstanceOf(UuidV7::class, Uuid::fromString($eventAggregateId));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
