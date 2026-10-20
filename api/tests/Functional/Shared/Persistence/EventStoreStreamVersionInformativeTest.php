<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Measures, against the real schema, the premise the informative-version decision rests on: the stream
 * UNIQUE spans `tenant_id`, which is written `NULL`, and PostgreSQL compares nulls as distinct — so two rows
 * sharing `(NULL, aggregate_id, aggregate_version)` both land. Runs inside a transaction that is always
 * rolled back, so it leaves no rows behind in the test database.
 *
 * @internal
 */
#[CoversNothing]
final class EventStoreStreamVersionInformativeTest extends KernelTestCase
{
    private const string INDEX_BECAME_ACTIVE = 'event_store_stream_version_uniq refused a duplicate version with '
        . 'tenant_id NULL: the index has become active, so the informative-version decision (ADR '
        . 'event-store-and-projections, D4 amendment) must be revisited.';

    public function testTheStreamUniqueAdmitsADuplicateVersionWhileTenantIsNull(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $aggregateId = Uuid::generate();
            $this->insertVersionOne($connection, $aggregateId);

            try {
                $this->insertVersionOne($connection, $aggregateId);
            } catch (UniqueConstraintViolationException) {
                $this->fail(self::INDEX_BECAME_ACTIVE);
            }

            $rows = $connection->fetchOne(
                'SELECT COUNT(*) FROM event_store WHERE aggregate_id = :aggregateId AND aggregate_version = 1',
                ['aggregateId' => $aggregateId],
            );
            $this->assertIsNumeric($rows);
            $this->assertSame(
                2,
                (int) $rows,
                self::INDEX_BECAME_ACTIVE,
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function insertVersionOne(Connection $connection, string $aggregateId): void
    {
        $connection->executeStatement(
            'INSERT INTO event_store (event_id, aggregate_id, aggregate_type, aggregate_version, event_name, '
            . 'event_version, payload, metadata, tenant_id, occurred_on, recorded_on) '
            . "VALUES (CAST(:eventId AS UUID), CAST(:aggregateId AS UUID), 'Test.Stream', 1, 'test.stream', 1, "
            . "'{}', '{}', NULL, clock_timestamp(), clock_timestamp())",
            ['eventId' => Uuid::generate(), 'aggregateId' => $aggregateId],
        );
    }
}
