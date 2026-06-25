<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogPruner;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the differentiated retention prune against a real Postgres: rows out of their
 * per-level window are deleted, rows inside it survive, and the longer `security` window keeps a
 * `security` row that a same-aged `activity` row loses.
 *
 * Each case runs inside a transaction that is always rolled back, so the prune's table-wide DELETE never
 * escapes the test and no rows are left behind — the suite has no DAMA auto-rollback and shares the dev
 * database connection. Rows are addressed by their minted id, so other rows in the shared table do not
 * affect the assertions.
 *
 * @internal
 */
#[CoversClass(DbalAuditLogPruner::class)]
final class AuditLogPrunerFunctionalTest extends KernelTestCase
{
    private const string ANCHOR = '2026-06-25T00:00:00+00:00';

    public function testItPrunesEachLevelPastItsWindowAndKeepsTheRest(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $writer = new DbalAuditLogWriter($connection);

            $activityStale = $this->seed($writer, AuditLevel::ACTIVITY, $this->daysBefore($anchor, 200));
            $activityFresh = $this->seed($writer, AuditLevel::ACTIVITY, $this->daysBefore($anchor, 10));
            $securitySurvivor = $this->seed($writer, AuditLevel::SECURITY, $this->daysBefore($anchor, 200));
            $securityStale = $this->seed($writer, AuditLevel::SECURITY, $this->daysBefore($anchor, 400));

            $pruner = new DbalAuditLogPruner($connection);
            $activityRemoved = $pruner->pruneOlderThan(AuditLevel::ACTIVITY, $this->daysBefore($anchor, 90));
            $securityRemoved = $pruner->pruneOlderThan(AuditLevel::SECURITY, $this->daysBefore($anchor, 365));

            $this->assertGreaterThanOrEqual(1, $activityRemoved);
            $this->assertGreaterThanOrEqual(1, $securityRemoved);

            $this->assertSame(0, $this->countRowsForId($connection, $activityStale), 'activity past 90d is pruned');
            $this->assertSame(1, $this->countRowsForId($connection, $activityFresh), 'activity within 90d survives');
            $this->assertSame(
                1,
                $this->countRowsForId($connection, $securitySurvivor),
                'a 200d security row survives — the longer window is what differentiates it from activity',
            );
            $this->assertSame(0, $this->countRowsForId($connection, $securityStale), 'security past 365d is pruned');
        });
    }

    private function seed(DbalAuditLogWriter $writer, AuditLevel $level, DateTimeImmutable $occurredOn): string
    {
        $entry = AuditLogEntry::create(
            'BANK_ACCOUNTS_VIEWED',
            $level,
            ActorContext::anonymous(),
            Uuid::generate(),
            $occurredOn,
        );
        $writer->write($entry);

        return $entry->id;
    }

    private function daysBefore(DateTimeImmutable $anchor, int $days): DateTimeImmutable
    {
        return $anchor->sub(new DateInterval('P' . $days . 'D'));
    }

    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $body($connection);
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
