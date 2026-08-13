<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogPruner;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the differentiated, chunked retention prune against a real Postgres: rows out of
 * their per-level window are deleted, rows inside it survive, the longer `security` window keeps a
 * `security` row that a same-aged `activity` row loses, and a batch size below the stale-row count proves
 * the chunk loop drains the level across several statements.
 *
 * Each case runs inside a transaction that is always rolled back, so the prune's DELETEs never escape the
 * test and no rows are left behind. Rows are addressed by their minted id, so other rows in the shared
 * table do not affect the assertions.
 *
 * @internal
 */
#[CoversClass(DbalAuditLogPruner::class)]
final class AuditLogPrunerFunctionalTest extends KernelTestCase
{
    private const string ANCHOR = '2026-06-25T00:00:00+00:00';

    private const int SMALL_BATCH = 2;

    public function testItPrunesEachLevelPastItsWindowInChunksAndKeepsTheRest(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $writer = new DbalAuditLogWriter($connection);
            $policy = new AuditRetentionPolicy(90, 365);

            // At a batch of 2 the drain loop, which stops only on a short batch, issues one DELETE per
            // eligible row at all three levels — so without this the runtime tracks whatever the shared
            // database happens to hold rather than what this case seeds, and `$removed` can only be
            // asserted as a lower bound. One statement bounds it and makes the count exact; the cut-off is
            // the latest of the plan's own thresholds, so it is a superset of everything the sweep can take.
            $connection->executeStatement(
                'DELETE FROM audit_log WHERE occurred_on < :cutoff',
                ['cutoff' => $this->leastRestrictiveThreshold($policy, $anchor)],
            );

            // Three stale activity rows so a batch of 2 must loop (2 then 1) to drain them.
            $staleActivity = [
                $this->seed($writer, AuditLevel::ACTIVITY, $this->daysBefore($anchor, 200)),
                $this->seed($writer, AuditLevel::ACTIVITY, $this->daysBefore($anchor, 150)),
                $this->seed($writer, AuditLevel::ACTIVITY, $this->daysBefore($anchor, 120)),
            ];
            $freshActivity = $this->seed($writer, AuditLevel::ACTIVITY, $this->daysBefore($anchor, 10));
            $survivingSecurity = $this->seed($writer, AuditLevel::SECURITY, $this->daysBefore($anchor, 200));
            $staleSecurity = $this->seed($writer, AuditLevel::SECURITY, $this->daysBefore($anchor, 400));

            $pruner = new DbalAuditLogPruner(
                $connection,
                new PostgresAdvisoryLock($connection),
                new NullLogger(),
                self::SMALL_BATCH,
            );
            $removed = $pruner->prune(...$policy->thresholdsAt($anchor));

            $this->assertSame(4, $removed, 'three stale activity + one stale security, and nothing else');

            foreach ($staleActivity as $id) {
                $this->assertSame(0, $this->countRowsForId($connection, $id), 'activity past 90d is pruned');
            }

            $this->assertSame(1, $this->countRowsForId($connection, $freshActivity), 'activity within 90d survives');
            $this->assertSame(
                1,
                $this->countRowsForId($connection, $survivingSecurity),
                'a 200d security row survives — the longer window is what differentiates it from activity',
            );
            $this->assertSame(0, $this->countRowsForId($connection, $staleSecurity), 'security past 365d is pruned');

            // Idempotent: a second prune over the same plan removes nothing new and leaves survivors intact.
            $pruner->prune(...(new AuditRetentionPolicy(90, 365))->thresholdsAt($anchor));

            $this->assertSame(1, $this->countRowsForId($connection, $freshActivity), 'survivor intact after a re-run');
            $this->assertSame(
                1,
                $this->countRowsForId($connection, $survivingSecurity),
                'survivor intact after a re-run',
            );
            $this->assertSame(
                0,
                $this->countRowsForId($connection, $staleSecurity),
                'a pruned row stays gone on a re-run',
            );
        });
    }

    public function testItRemovesNothingWhenNoRowIsPastItsWindow(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $writer = new DbalAuditLogWriter($connection);

            $recentActivity = $this->seed($writer, AuditLevel::ACTIVITY, $this->daysBefore($anchor, 10));
            $recentSecurity = $this->seed($writer, AuditLevel::SECURITY, $this->daysBefore($anchor, 100));

            $pruner = new DbalAuditLogPruner(
                $connection,
                new PostgresAdvisoryLock($connection),
                new NullLogger(),
            );
            $pruner->prune(...(new AuditRetentionPolicy(90, 365))->thresholdsAt($anchor));

            $this->assertSame(1, $this->countRowsForId($connection, $recentActivity), 'in-window activity survives');
            $this->assertSame(1, $this->countRowsForId($connection, $recentSecurity), 'in-window security survives');
        });
    }

    public function testItPrunesChangeRowsOnlyOncePastTheComplianceFloorAndKeepsRecentEvidence(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $writer = new DbalAuditLogWriter($connection);

            $staleChange = $this->seed($writer, AuditLevel::CHANGE, $this->daysBefore($anchor, 6 * 365));
            $recentChange = $this->seed($writer, AuditLevel::CHANGE, $this->daysBefore($anchor, 4 * 365));

            $pruner = new DbalAuditLogPruner($connection, new PostgresAdvisoryLock($connection), new NullLogger());
            $pruner->prune(...(new AuditRetentionPolicy(90, 365))->thresholdsAt($anchor));

            $this->assertSame(
                0,
                $this->countRowsForId($connection, $staleChange),
                'a change row past the five-year floor becomes prune-eligible',
            );
            $this->assertSame(
                1,
                $this->countRowsForId($connection, $recentChange),
                'a change row within the floor is kept as compliance evidence, despite no privacy ceiling',
            );
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

    /**
     * The latest of the plan's per-level cut-offs, derived rather than written as a literal so a change to
     * the policy cannot quietly return the backlog while every assertion here stays green.
     */
    private function leastRestrictiveThreshold(AuditRetentionPolicy $policy, DateTimeImmutable $anchor): string
    {
        $latest = null;

        foreach ($policy->thresholdsAt($anchor) as $auditRetentionThreshold) {
            if (null === $latest || $auditRetentionThreshold->deleteBefore > $latest) {
                $latest = $auditRetentionThreshold->deleteBefore;
            }
        }

        $this->assertInstanceOf(
            DateTimeImmutable::class,
            $latest,
            'the policy produced no threshold, so this bound covers nothing',
        );

        return $latest->format(DateTimeImmutable::ATOM);
    }

    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);

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
