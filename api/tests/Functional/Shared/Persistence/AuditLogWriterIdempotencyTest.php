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
     * Every row THIS WRITER writes holds one shape — not every row in a deployed table, where the ones
     * written before the coercion are still `[]` and no backfill flattens them. `jsonb_typeof` is what
     * asks, because decoding the string cannot tell the two apart: `json_decode('{}', true)` and
     * `json_decode('[]', true)` are both `[]` in PHP — which is why the neighbouring case above stays
     * green either way and proves nothing about the shape.
     *
     * The second half is the falsification that keeps the cast honest. `JSON_FORCE_OBJECT` would satisfy
     * the first assertion and fail this one, rewriting the role lists into `{"0": …}` and changing what
     * the trail records about a roles change.
     */
    public function testMetadataIsStoredAsAnObjectWithoutRewritingNestedLists(): void
    {
        $this->withWriter(function (AuditLogWriter $writer, Connection $connection): void {
            $empty = AuditLogEntry::create(
                'BANK_ACCOUNTS_VIEWED',
                AuditLevel::ACTIVITY,
                ActorContext::anonymous(),
                Uuid::generate(),
                new DateTimeImmutable('2026-05-01T09:00:00+00:00'),
            );
            $withLists = AuditLogEntry::create(
                'USER_ROLES_CHANGED',
                AuditLevel::SECURITY,
                ActorContext::forUser(Uuid::generate()),
                Uuid::generate(),
                new DateTimeImmutable('2026-05-01T09:00:01+00:00'),
                metadata: ['previous_roles' => ['ROLE_USER'], 'new_roles' => ['ROLE_USER', 'ROLE_ADMIN']],
            );

            $writer->write($empty);
            $writer->write($withLists);

            $this->assertSame(
                'object',
                $connection->fetchOne(
                    'SELECT jsonb_typeof(metadata) FROM audit_log WHERE id = :id',
                    ['id' => $empty->id],
                ),
                'an entry carrying no metadata still stores an object',
            );

            $shapes = $connection->fetchAssociative(
                'SELECT jsonb_typeof(metadata) AS root, '
                . "jsonb_typeof(metadata->'previous_roles') AS previous, "
                . "jsonb_typeof(metadata->'new_roles') AS new "
                . 'FROM audit_log WHERE id = :id',
                ['id' => $withLists->id],
            );
            $this->assertIsArray($shapes);
            $this->assertSame('object', $shapes['root'] ?? null);
            $this->assertSame('array', $shapes['previous'] ?? null, 'a nested list stays a JSON array');
            $this->assertSame('array', $shapes['new'] ?? null);
        });
    }

    /**
     * A caller asking for the port gets this implementation. Two independent mechanisms name it — the
     * `#[AsAlias]` attribute, and the service loader's rule that aliases a singly-implemented interface
     * to its one implementer — so what is pinned here is the resolution rather than either mechanism,
     * and a second implementer arriving is what makes the difference visible. The container is only read.
     */
    public function testThePortResolvesToTheDbalWriter(): void
    {
        self::bootKernel();

        $writer = self::getContainer()->get(AuditLogWriter::class);

        $this->assertInstanceOf(DbalAuditLogWriter::class, $writer);
    }

    /**
     * The writer is constructed directly rather than resolved: the cases above assert the SQL of one
     * concrete implementation against real Postgres, so they must not follow whatever the port's alias
     * happens to point at — that binding is the subject of
     * {@see testThePortResolvesToTheDbalWriter()}. The connection is the container's, so the work runs
     * inside the transaction rolled back here.
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
