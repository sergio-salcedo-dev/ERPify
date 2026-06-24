<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the storage layer of the audit log against a real Postgres. Idempotency comes from
 * the `id` primary key plus `INSERT … ON CONFLICT (id) DO NOTHING`: re-writing the same entry is a silent
 * no-op, while a regenerated entry with the same business data is a distinct row (no semantic dedup).
 *
 * Each case runs inside a transaction that is always rolled back, so the test leaves no rows behind — the
 * suite has no DAMA auto-rollback and shares the dev database connection.
 *
 * @internal
 */
#[CoversClass(DbalAuditLogWriter::class)]
final class AuditLogWriterIdempotencyTest extends KernelTestCase
{
    public function testWritingTheSameEntryTwiceWritesExactlyOneRow(): void
    {
        $this->withWriter(function (AuditLogWriter $writer, Connection $connection): void {
            $entry = AuditLogEntry::create(
                'BANK_ACCOUNTS_VIEWED',
                AuditLevel::ACTIVITY,
                ActorContext::anonymous(),
                Uuid::generate(),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            );

            $writer->write($entry);
            $this->assertSame(1, $this->countRowsForId($connection, $entry->id), 'first write inserts one row');

            $writer->write($entry);
            $this->assertSame(1, $this->countRowsForId($connection, $entry->id), 'second write must be a no-op');
        });
    }

    public function testRegeneratedEntriesWithTheSameDataProduceDistinctRows(): void
    {
        $this->withWriter(function (AuditLogWriter $writer, Connection $connection): void {
            $correlationId = Uuid::generate();
            $occurredOn = new DateTimeImmutable('2026-01-02T00:00:00+00:00');

            $first = AuditLogEntry::create(
                'BANK_ACCOUNTS_VIEWED',
                AuditLevel::ACTIVITY,
                ActorContext::anonymous(),
                $correlationId,
                $occurredOn,
            );
            $second = AuditLogEntry::create(
                'BANK_ACCOUNTS_VIEWED',
                AuditLevel::ACTIVITY,
                ActorContext::anonymous(),
                $correlationId,
                $occurredOn,
            );

            $this->assertNotSame($first->id, $second->id, 'each create() mints a fresh id');

            $writer->write($first);
            $writer->write($second);

            $count = $connection->fetchOne(
                'SELECT COUNT(*) FROM audit_log WHERE correlation_id = :cid',
                ['cid' => $correlationId],
            );
            $this->assertIsNumeric($count);
            $this->assertSame(2, (int) $count, 'distinct ids are distinct rows; no semantic dedup');
        });
    }

    public function testItRoundTripsEveryColumnAgainstPostgres(): void
    {
        $this->withWriter(function (AuditLogWriter $writer, Connection $connection): void {
            $actorId = Uuid::generate();
            $resourceId = Uuid::generate();
            $correlationId = Uuid::generate();
            $occurredOn = new DateTimeImmutable('2026-03-02T10:11:12.123456+00:00');

            $entry = AuditLogEntry::create(
                'ACCESS_DENIED',
                AuditLevel::SECURITY,
                ActorContext::forUser($actorId),
                $correlationId,
                $occurredOn,
                resource: AuditResource::of('Bank', $resourceId),
                metadata: ['filters' => ['status' => 'active']],
                ip: '203.0.113.7',
                userAgent: 'Mozilla/5.0',
            );

            $writer->write($entry);

            $row = $connection->fetchAssociative('SELECT * FROM audit_log WHERE id = :id', ['id' => $entry->id]);
            $this->assertIsArray($row);

            $this->assertSame('security', $row['level'] ?? null);
            $this->assertSame('ACCESS_DENIED', $row['action'] ?? null);
            $this->assertSame('user', $row['actor_type'] ?? null);
            $this->assertSame($actorId, $row['actor_id'] ?? null);
            $this->assertSame($correlationId, $row['correlation_id'] ?? null);
            $this->assertSame('Bank', $row['resource_type'] ?? null);
            $this->assertSame($resourceId, $row['resource_id'] ?? null);
            $this->assertSame('203.0.113.7', $row['ip'] ?? null);
            $this->assertSame('Mozilla/5.0', $row['user_agent'] ?? null);

            $metadata = $row['metadata'] ?? null;
            $this->assertIsString($metadata);
            $this->assertSame(
                ['filters' => ['status' => 'active']],
                \json_decode($metadata, true, 512, JSON_THROW_ON_ERROR),
            );

            $storedOccurredOn = $row['occurred_on'] ?? null;
            $this->assertIsString($storedOccurredOn);
            $stored = new DateTimeImmutable($storedOccurredOn);
            $this->assertSame(
                $occurredOn->getTimestamp(),
                $stored->getTimestamp(),
                'occurred_on round-trips to the same instant',
            );
            $this->assertSame(
                $occurredOn->format('u'),
                $stored->format('u'),
                'occurred_on preserves microsecond precision (TIMESTAMP(6))',
            );
        });
    }

    public function testItWritesNullsForAMinimalAnonymousEntry(): void
    {
        $this->withWriter(function (AuditLogWriter $writer, Connection $connection): void {
            $entry = AuditLogEntry::create(
                'BANK_ACCOUNTS_VIEWED',
                AuditLevel::ACTIVITY,
                ActorContext::anonymous(),
                Uuid::generate(),
                new DateTimeImmutable('2026-04-01T08:00:00+00:00'),
            );

            $writer->write($entry);

            $row = $connection->fetchAssociative('SELECT * FROM audit_log WHERE id = :id', ['id' => $entry->id]);
            $this->assertIsArray($row);

            $this->assertSame('anonymous', $row['actor_type'] ?? null);
            $this->assertNull($row['actor_id'] ?? null);
            $this->assertNull($row['resource_type'] ?? null);
            $this->assertNull($row['resource_id'] ?? null);
            $this->assertNull($row['ip'] ?? null);
            $this->assertNull($row['user_agent'] ?? null);

            $metadata = $row['metadata'] ?? null;
            $this->assertIsString($metadata);
            $this->assertSame([], \json_decode($metadata, true, 512, JSON_THROW_ON_ERROR));
        });
    }

    /**
     * The writer is built by hand against the real database connection from the container: the
     * `#[AsAlias]` service has no production consumer yet, so the autoconfigured private alias is
     * pruned at compile time and cannot be resolved from the container. Its wiring is exercised
     * end-to-end once a caller exists; here the concern is the SQL against real Postgres.
     *
     * @param callable(AuditLogWriter, Connection): void $work
     */
    private function withWriter(callable $work): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $writer = new DbalAuditLogWriter($connection);

        $connection->beginTransaction();

        try {
            $work($writer, $connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function countRowsForId(Connection $connection, string $id): int
    {
        $rowCount = $connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE id = :id', ['id' => $id]);
        $this->assertIsNumeric($rowCount);

        return (int) $rowCount;
    }
}
