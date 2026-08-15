<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Event\Infrastructure\Messenger\DbalFailedMessagePruner;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asks Postgres what the prune's batching statement actually does, because the source gate can only prove the
 * clause is written and no behavioural test can observe lock ORDER without a concurrent contender.
 *
 * The statement is captured from the pruner itself rather than restated here: explaining a copy kept in a
 * test would only prove the test's own string is ordered and locked.
 *
 * @internal
 */
#[CoversNothing]
final class FailedMessagePrunePlanFunctionalTest extends KernelTestCase
{
    private const string ANCHOR = '2026-06-25T00:00:00+00:00';

    private const int BATCH = 5000;

    private const int SEEDED_ROWS = 2000;

    #[Test]
    public function theBatchAcquiresItsLocksInIdOrder(): void
    {
        $plan = $this->planFor($this->statementThePrunerEmits());

        $this->assertStringContainsString(
            'LockRows',
            $plan,
            'The batching SELECT no longer locks the rows it selects, so its ordering orders nothing: the '
            . 'outer DELETE re-locks in the order of whatever node unique-ified the subquery, and a drain '
            . 'can meet the transport consumer head-on. Plan: ' . $plan,
        );
        // Matched qualified-or-bare: the outer DELETE targets the same table, so the subquery is aliased and
        // Postgres prints `messenger_messages_1.id` where a standalone statement prints `id`.
        $this->assertMatchesRegularExpression(
            '/"Sort Key": \["(?:\w+\.)?id"\]|messenger_messages_pkey/',
            $plan,
            'The batch no longer establishes an order over the rows it locks: the plan has neither a Sort on '
            . '`id` nor a primary-key walk. Plan: ' . $plan,
        );
    }

    private function statementThePrunerEmits(): string
    {
        $captured = '';
        // Stubs, not mocks: nothing here is an expectation about how the class is called. `fetchOne` grants
        // the advisory lock, and `executeStatement` returns a short batch so the drain ends after one pass —
        // the statement it emits is the whole subject.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(true);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$captured): int {
                $captured = $sql;

                return 0;
            },
        );

        (new DbalFailedMessagePruner($connection, new PostgresAdvisoryLock($connection), new NullLogger(), self::BATCH))
            ->pruneFailedBefore(new DateTimeImmutable(self::ANCHOR))
        ;

        $this->assertNotSame('', $captured, 'the pruner emitted no statement to explain');

        return $captured;
    }

    /**
     * The plan is a costed choice, so asking about it over whatever the shared database happens to hold makes
     * the answer a property of the ambient data rather than of the statement. Measured: with one population
     * the planner walked the primary key and deleting `ORDER BY id` left this green; with another it sorted
     * and the same deletion went red. So the population is seeded here — enough eligible rows to make the
     * batch real, `ANALYZE`d so the planner is not costing against stale statistics — and rolled back.
     */
    private function planFor(string $statement): string
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) '
                . "SELECT '{}', '{}', :queue, :createdAt, :createdAt FROM generate_series(1, :rows)",
                [
                    'queue' => DbalFailedMessagePruner::FAILED_QUEUE,
                    'createdAt' => (new DateTimeImmutable(self::ANCHOR))->modify('-90 days'),
                    'rows' => self::SEEDED_ROWS,
                ],
                ['createdAt' => Types::DATETIME_IMMUTABLE, 'rows' => Types::INTEGER],
            );
            $connection->executeStatement('ANALYZE messenger_messages');

            $plan = $connection->fetchOne(
                'EXPLAIN (FORMAT JSON) ' . $statement,
                [
                    'queue' => DbalFailedMessagePruner::FAILED_QUEUE,
                    'threshold' => new DateTimeImmutable(self::ANCHOR),
                    'batch' => self::BATCH,
                ],
                [
                    'threshold' => Types::DATETIME_IMMUTABLE,
                    'batch' => Types::INTEGER,
                ],
            );

            $this->assertIsString($plan);

            return $plan;
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
