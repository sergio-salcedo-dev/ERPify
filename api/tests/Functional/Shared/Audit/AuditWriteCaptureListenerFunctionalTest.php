<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Audit\Infrastructure\Persistence\AuditWriteCaptureListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for write-side change-data-capture against a real Postgres. The `onFlush` listener turns
 * every insert/update/delete of an audited aggregate into a `change` row carrying the field-level diff, and
 * does so inside the writing transaction so the row commits with the business change or not at all.
 *
 * Every case runs inside a transaction that is always rolled back (the suite shares the dev database and has
 * no auto-rollback), so the tests leave no rows behind.
 *
 * @internal
 */
#[CoversClass(AuditWriteCaptureListener::class)]
final class AuditWriteCaptureListenerFunctionalTest extends KernelTestCase
{
    public function testCreatingAnAuditedAggregateWritesAChangeRowWithItsFieldDiff(): void
    {
        $this->inRolledBackTransaction(function (EntityManagerInterface $em, Connection $connection): void {
            $id = Uuid::generate();
            $bank = $this->bankWith($id);

            $em->persist($bank);
            $em->flush();

            $row = $this->changeRow($connection, $id, 'BANK_CREATED');
            $this->assertSame('change', $row['level'] ?? null);
            $this->assertSame('Bank', $row['resource_type'] ?? null);
            $this->assertSame($id, $row['resource_id'] ?? null);

            $this->assertNull($this->changedValue($row, 'name', 'old'), 'an insert has no prior value');
            $this->assertSame($bank->getName(), $this->changedValue($row, 'name', 'new'));
            $this->assertSame($bank->getShortName(), $this->changedValue($row, 'shortName', 'new'));
            $this->assertSame('CREATED', $this->operationOf($row));
        });
    }

    public function testUpdatingAnAuditedAggregateRecordsTheBeforeAndAfterOfEachChangedField(): void
    {
        $this->inRolledBackTransaction(function (EntityManagerInterface $em, Connection $connection): void {
            $id = Uuid::generate();
            $bank = $this->bankWith($id);
            $em->persist($bank);
            $em->flush();

            $originalName = $bank->getName();

            $bank->rename('Audited Renamed Bank', 'AUDREN');
            $em->flush();

            $row = $this->changeRow($connection, $id, 'BANK_UPDATED');
            $this->assertSame($originalName, $this->changedValue($row, 'name', 'old'));
            $this->assertSame('Audited Renamed Bank', $this->changedValue($row, 'name', 'new'));
            $this->assertSame('UPDATED', $this->operationOf($row));
        });
    }

    public function testDeletingAnAuditedAggregateRecordsADeletionRowForTheResource(): void
    {
        $this->inRolledBackTransaction(function (EntityManagerInterface $em, Connection $connection): void {
            $id = Uuid::generate();
            $bank = $this->bankWith($id);
            $em->persist($bank);
            $em->flush();

            $finalName = $bank->getName();
            $finalShortName = $bank->getShortName();

            $em->remove($bank);
            $em->flush();

            $row = $this->changeRow($connection, $id, 'BANK_DELETED');
            $this->assertSame('Bank', $row['resource_type'] ?? null);
            $this->assertSame($id, $row['resource_id'] ?? null);

            $this->assertSame(
                $finalName,
                $this->changedValue($row, 'name', 'old'),
                'the delete snapshots the final state',
            );
            $this->assertNull($this->changedValue($row, 'name', 'new'), 'with nothing after — the row is gone');
            $this->assertSame($finalShortName, $this->changedValue($row, 'shortName', 'old'));
            $this->assertSame('DELETED', $this->operationOf($row));
        });
    }

    public function testTheChangeRowSealsTheAmbientSystemActorCorrelationAndInstant(): void
    {
        $this->inRolledBackTransaction(function (EntityManagerInterface $em, Connection $connection): void {
            $id = Uuid::generate();
            $bank = $this->bankWith($id);

            $em->persist($bank);
            $em->flush();

            $row = $this->changeRow($connection, $id, 'BANK_CREATED');

            // No HTTP request is in flight under a kernel test, so the ambient actor seals as system.
            $this->assertSame('system', $row['actor_type'] ?? null);
            $this->assertArrayHasKey('actor_id', $row);
            $this->assertNull($row['actor_id'], 'a system act carries no actor id');

            $correlationId = $row['correlation_id'] ?? null;
            $this->assertIsString($correlationId);
            $this->assertNotSame('', $correlationId, 'the correlation id is sealed, never null');

            $occurredOn = $row['occurred_on'] ?? null;
            $this->assertIsString($occurredOn);
            $this->assertNotSame('', $occurredOn, 'the instant is sealed from the clock');
        });
    }

    public function testTheChangeRowRollsBackAtomicallyWithTheBusinessWrite(): void
    {
        self::bootKernel();
        $em = $this->entityManager();
        $connection = $em->getConnection();

        $id = Uuid::generate();
        $bank = $this->bankWith($id);

        $connection->beginTransaction();

        try {
            $em->persist($bank);
            $em->flush();

            $this->assertSame(1, $this->countChangeRows($connection, $id), 'the change row is visible inside the tx');
            $this->assertSame(1, $this->countBankRows($connection, $id), 'so is the business row');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }

        $this->assertSame(0, $this->countChangeRows($connection, $id), 'rolling back discards the change row');
        $this->assertSame(0, $this->countBankRows($connection, $id), 'and the business row, atomically');
    }

    public function testItDoesNotAuditAWriteThatRunsOutsideATransaction(): void
    {
        self::bootKernel();
        $em = $this->entityManager();
        $connection = $em->getConnection();

        $id = Uuid::generate();
        $bank = $this->bankWith($id);

        try {
            $em->persist($bank);
            // No ambient transaction: out-of-band persistence (the fixtures/seed path), not an audited
            // business action — the write lands, but the trail leaves it uncaptured.
            $em->flush();

            $this->assertSame(1, $this->countBankRows($connection, $id), 'the write itself still lands');
            $this->assertSame(0, $this->countChangeRows($connection, $id), 'a non-transactional write is not audited');
        } finally {
            $connection->executeStatement('DELETE FROM bank WHERE id = :id', ['id' => $id]);
        }
    }

    public function testItIgnoresAnEntityThatDidNotOptIntoTheTrail(): void
    {
        $this->inRolledBackTransaction(function (EntityManagerInterface $em, Connection $connection): void {
            $organizationId = Uuid::generate();
            $organization = Organization::provision($organizationId, 'Acme Holdings ' . $organizationId);

            $em->persist($organization);
            $em->flush();

            $this->assertSame(
                0,
                $this->countChangeRows($connection, $organizationId),
                'a non-audited entity emits no row',
            );
        });
    }

    /**
     * @param callable(EntityManagerInterface, Connection): void $work
     */
    private function inRolledBackTransaction(callable $work): void
    {
        self::bootKernel();
        $em = $this->entityManager();
        $connection = $em->getConnection();

        $connection->beginTransaction();

        try {
            $work($em, $connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function bankWith(string $id): Bank
    {
        $token = \strtoupper(\substr(\str_replace('-', '', $id), 0, 8));

        return Bank::create($id, 'Audited Bank ' . $id, 'AUD' . $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function changeRow(Connection $connection, string $resourceId, string $action): array
    {
        $row = $connection->fetchAssociative(
            "SELECT * FROM audit_log WHERE level = 'change' AND resource_id = :id AND action = :action",
            ['id' => $resourceId, 'action' => $action],
        );

        $this->assertIsArray($row, \sprintf('expected one %s change row for %s', $action, $resourceId));

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function changedValue(array $row, string $field, string $side): mixed
    {
        $metadata = $row['metadata'] ?? null;
        $this->assertIsString($metadata);

        $decoded = \json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('changes', $decoded);
        $this->assertIsArray($decoded['changes']);
        $this->assertArrayHasKey($field, $decoded['changes']);

        $change = $decoded['changes'][$field];
        $this->assertIsArray($change);

        return $change[$side] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function operationOf(array $row): mixed
    {
        $metadata = $row['metadata'] ?? null;
        $this->assertIsString($metadata);

        $decoded = \json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('operation', $decoded);

        return $decoded['operation'];
    }

    private function countChangeRows(Connection $connection, string $resourceId): int
    {
        $count = $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE level = 'change' AND resource_id = :id",
            ['id' => $resourceId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function countBankRows(Connection $connection, string $id): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM bank WHERE id = :id', ['id' => $id]);
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
