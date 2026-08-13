<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogPruner;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asks the planner whether the prune's batching statement actually acquires its locks in `id` order.
 *
 * The clause the whole argument rests on is `FOR UPDATE`, and nothing behavioural can see it: both
 * functional cases assert which rows survive, which the exemption in the inner `WHERE` decides on its own,
 * and acquisition ORDER is not observable from a single holder — it needs a concurrent contender, not one
 * transaction holding and another probing. So this asks the planner instead, over the statement the class
 * actually emits rather than a copy kept in a test, exactly as {@see ObservesRowLocksOnASecondConnection}
 * already does for the sibling `identity_user` lock.
 *
 * `EXPLAIN` alone, never `ANALYZE`: the statement carries `FOR UPDATE`, and executing it here would take
 * real locks and delete real rows.
 *
 * This is the assertion `AuditPruneStatementGateTest` cannot make. That gate proves the clause is still
 * written; this one proves the clause still does what it is written for — a `LockRows` node over rows the
 * plan reaches in `id` order. Either shape that establishes the order is accepted, because which one the
 * planner picks is a function of table statistics and pinning one would go red on an autovacuum rather than
 * on a defect.
 *
 * @internal
 */
#[CoversNothing]
final class AuditPrunePlanFunctionalTest extends KernelTestCase
{
    private const string ANCHOR = '2026-06-25T00:00:00+00:00';

    private const int BATCH = 5000;

    #[Test]
    public function theBatchAcquiresItsLocksInIdOrder(): void
    {
        $plan = $this->planFor($this->statementThePrunerEmits());

        $this->assertStringContainsString(
            'LockRows',
            $plan,
            'The batching SELECT no longer locks the rows it selects, so its ordering orders nothing: the '
            . 'outer DELETE re-locks in the order of whatever node unique-ified the subquery. Plan: ' . $plan,
        );
        // The sort key is matched qualified-or-bare: the outer DELETE targets the same table, so the
        // subquery is aliased and Postgres prints `audit_log_1.id` where a standalone statement prints `id`.
        $this->assertMatchesRegularExpression(
            '/"Sort Key": \["(?:\w+\.)?id"\]|audit_log_pkey/',
            $plan,
            'The batch no longer establishes an order over the rows it locks: the plan has neither a Sort on '
            . '`id` nor a primary-key walk. The prune can then take rows in the opposite direction to the '
            . 'actor-axis erasure, which acquires ascending by `id`, and the two deadlock. Plan: ' . $plan,
        );
    }

    /**
     * The SQL {@see DbalAuditLogPruner} emits, captured from the class itself — asking the planner about a
     * string kept in a test would only prove the test's own copy is ordered and locked.
     */
    private function statementThePrunerEmits(): string
    {
        $captured = '';
        // Stubs, not mocks: nothing here is an expectation about how the class is called. `fetchOne` grants
        // the advisory lock, and `executeStatement` returns a short batch so the drain loop ends after one
        // pass — the statement it emits is the whole subject.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(true);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$captured): int {
                $captured = $sql;

                return 0;
            },
        );

        $thresholds = (new AuditRetentionPolicy(90, 365))->thresholdsAt(new DateTimeImmutable(self::ANCHOR));
        (new DbalAuditLogPruner($connection, new PostgresAdvisoryLock($connection), new NullLogger(), self::BATCH))
            ->prune(...$thresholds)
        ;

        $this->assertNotSame('', $captured, 'the pruner emitted no statement to explain');

        return $captured;
    }

    private function planFor(string $statement): string
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        $plan = $connection->fetchOne(
            'EXPLAIN (FORMAT JSON) ' . $statement,
            [
                'level' => 'security',
                'threshold' => new DateTimeImmutable(self::ANCHOR),
                'batch' => self::BATCH,
                'exemptActions' => ['GDPR_SUBJECT_ERASED', 'GDPR_ERASURE_EXECUTED'],
            ],
            [
                'threshold' => Types::DATETIMETZ_IMMUTABLE,
                'batch' => Types::INTEGER,
                'exemptActions' => ArrayParameterType::STRING,
            ],
        );

        $this->assertIsString($plan);

        return $plan;
    }
}
