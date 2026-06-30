<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Audit\Infrastructure\Persistence\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Erpify\Backoffice\Audit\Domain\AuditEventDetail;
use Erpify\Backoffice\Audit\Domain\Repository\AuditEventDetailRepository;
use Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal\DbalAuditTimelineRepository;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration lock for the by-id detail read (`findById`) over the entity-free `audit_log` table,
 * against REAL Postgres (never SQLite): a `change` row hydrates every column and decodes its diff,
 * a non-change row carries an empty diff, and a well-formed but absent id returns null. The keyset
 * search path is covered separately by {@see DbalAuditTimelineRepositoryTest}.
 *
 * Every test runs inside {@see inRolledBackTransaction}: the suite has no DAMA auto-rollback and
 * shares the dev database, so the table is truncated and re-seeded inside a transaction that is
 * always rolled back — the seeded rows never leak.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DbalAuditTimelineRepository::class)]
#[CoversClass(AuditEventDetail::class)]
final class DbalAuditEventDetailRepositoryTest extends KernelTestCase
{
    private const string ACTOR_ID = '11111111-1111-7111-8111-111111111111';

    private const string RESOURCE_ID = '22222222-2222-7222-8222-222222222222';

    private Connection $connection;

    private AuditEventDetailRepository $detailRepository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $detailRepository = self::getContainer()->get(AuditEventDetailRepository::class);
        $this->assertInstanceOf(DbalAuditTimelineRepository::class, $detailRepository);
        $this->detailRepository = $detailRepository;
    }

    public function testFindByIdHydratesEveryColumnAndDecodesTheChangeDiff(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();
            $writer = new DbalAuditLogWriter($this->connection);
            $entry = AuditLogEntry::create(
                'BANK_UPDATED',
                AuditLevel::CHANGE,
                ActorContext::forUser(self::ACTOR_ID),
                Uuid::generate(),
                new DateTimeImmutable('2026-03-01T11:30:00.123456+00:00'),
                AuditResource::of('Bank', self::RESOURCE_ID),
                ['changes' => ['name' => ['old' => 'BBVA', 'new' => 'BBVA S.A.']]],
            );
            $writer->write($entry);

            $detail = $this->detailRepository->findById($entry->id);

            $this->assertInstanceOf(AuditEventDetail::class, $detail);
            $this->assertSame($entry->id, $detail->id);
            $this->assertSame('change', $detail->level);
            $this->assertSame('BANK_UPDATED', $detail->action);
            $this->assertSame('user', $detail->actorType);
            $this->assertSame(self::ACTOR_ID, $detail->actorId);
            $this->assertSame('Bank', $detail->resourceType);
            $this->assertSame(self::RESOURCE_ID, $detail->resourceId);
            $this->assertFalse($detail->actorErased);
            $this->assertSame(
                '2026-03-01T11:30:00.123456+00:00',
                $detail->occurredOn->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            );
            // JSONB does not preserve object-key order, so assert the diff leaves, not the array shape.
            $change = $this->changeFor($detail, 'name');
            $this->assertSame('BBVA', $change['old'] ?? null);
            $this->assertSame('BBVA S.A.', $change['new'] ?? null);
        });
    }

    public function testFindByIdReturnsEmptyMetadataForANonChangeRow(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();
            $writer = new DbalAuditLogWriter($this->connection);
            $entry = AuditLogEntry::create(
                'VIEW',
                AuditLevel::ACTIVITY,
                ActorContext::anonymous(),
                Uuid::generate(),
                new DateTimeImmutable('2026-03-01T11:00:00.000000+00:00'),
            );
            $writer->write($entry);

            $detail = $this->detailRepository->findById($entry->id);

            $this->assertInstanceOf(AuditEventDetail::class, $detail);
            $this->assertSame([], $detail->metadata, 'a non-change row carries no diff');
            $this->assertNull($detail->actorId);
            $this->assertNull($detail->resourceType);
            $this->assertNull($detail->resourceId);
        });
    }

    public function testFindByIdReturnsNullForAWellFormedButAbsentId(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();

            $this->assertNotInstanceOf(AuditEventDetail::class, $this->detailRepository->findById(Uuid::generate()));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function changeFor(AuditEventDetail $detail, string $field): array
    {
        $changes = $detail->metadata['changes'] ?? null;
        $this->assertIsArray($changes);
        $change = $changes[$field] ?? null;
        $this->assertIsArray($change);

        /** @phpstan-var array<string, mixed> */
        return $change;
    }

    private function truncate(): void
    {
        $this->connection->executeStatement('TRUNCATE audit_log RESTART IDENTITY');
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
