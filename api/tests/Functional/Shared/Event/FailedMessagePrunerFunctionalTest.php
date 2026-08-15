<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Event\Infrastructure\Messenger\DbalFailedMessagePruner;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the failure-transport prune against a real Postgres. The two reds this has to carry
 * are the window (one row just outside it goes, one just inside stays) and the queue: `async` and `failed`
 * are ONE table told apart by `queue_name`, so a statement that forgets the predicate deletes messages that
 * are in flight — a defect no assertion about the failed queue alone can see.
 *
 * Each case runs inside a transaction that is always rolled back, so no DELETE escapes the test. Rows are
 * addressed by their minted id, so whatever else the shared table holds cannot move the assertions.
 *
 * @internal
 */
#[CoversClass(DbalFailedMessagePruner::class)]
final class FailedMessagePrunerFunctionalTest extends KernelTestCase
{
    private const string ANCHOR = '2026-06-25T00:00:00+00:00';

    private const int RETENTION_DAYS = 30;

    private const int SMALL_BATCH = 2;

    private const int ELIGIBLE_ROWS = 3;

    #[Test]
    public function itPrunesPastTheWindowInChunksAndKeepsWhatIsInside(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $threshold = $this->daysBefore($anchor, self::RETENTION_DAYS);

            // Bound what the sweep can find to what this case seeds: at a batch of 2 the drain loop ends
            // only on a short batch, so any pre-existing stale row would make `$removed` an unknown.
            $connection->executeStatement(
                'DELETE FROM messenger_messages WHERE created_at < :cutoff',
                ['cutoff' => $threshold],
                ['cutoff' => Types::DATETIME_IMMUTABLE],
            );

            // Three stale failed rows, so a batch of 2 must loop (2 then 1) to drain them.
            $stale = [
                $this->seed($connection, 'failed', $this->daysBefore($anchor, 90)),
                $this->seed($connection, 'failed', $this->daysBefore($anchor, 60)),
                $this->seed($connection, 'failed', $this->daysBefore($anchor, 31)),
            ];

            // One second either side of the cut-off, and one exactly on it. The pair either side is the
            // window's red for a threshold off by a day; the row ON the boundary is the red for a
            // comparison that slips to `<=`, which the pair alone cannot see — `created_at` is a
            // second-precision column, so equality is representable and reachable.
            $justOutside = $this->seed($connection, 'failed', $threshold->modify('-1 second'));
            $onTheBoundary = $this->seed($connection, 'failed', $threshold);
            $justInside = $this->seed($connection, 'failed', $threshold->modify('+1 second'));

            $removed = $this->pruner($connection)->pruneFailedBefore($threshold);

            $this->assertSame(4, $removed, 'the three stale rows and the one a second outside the window');

            foreach ([...$stale, $justOutside] as $goneId) {
                $this->assertSame(0, $this->countRowsForId($connection, $goneId));
            }

            $this->assertSame(
                1,
                $this->countRowsForId($connection, $onTheBoundary),
                'a row exactly at the cut-off is not PAST the window, so the prune leaves it',
            );

            $this->assertSame(
                1,
                $this->countRowsForId($connection, $justInside),
                'a row one second inside the window is not past it',
            );

            $this->assertSame(
                0,
                $this->pruner($connection)->pruneFailedBefore($threshold),
                're-running the same sweep removes nothing: the prune is idempotent',
            );
        });
    }

    #[Test]
    public function itNeverTouchesAMessageThatIsStillInFlight(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $threshold = $this->daysBefore($anchor, self::RETENTION_DAYS);

            // Same table, same age, past the same window — the ONLY thing that must save it is its queue.
            $inFlight = $this->seed($connection, 'async', $this->daysBefore($anchor, 90));
            $failed = $this->seed($connection, 'failed', $this->daysBefore($anchor, 90));

            $removed = $this->pruner($connection)->pruneFailedBefore($threshold);

            $this->assertGreaterThanOrEqual(1, $removed, 'the seeded failed row was eligible and was taken');
            $this->assertSame(0, $this->countRowsForId($connection, $failed));
            $this->assertSame(
                1,
                $this->countRowsForId($connection, $inFlight),
                'an async message of the same age shares this table and must survive the failed prune',
            );
        });
    }

    #[Test]
    public function itStopsDrainingWhenItsWallClockBudgetIsSpent(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $threshold = $this->daysBefore($anchor, self::RETENTION_DAYS);

            $connection->executeStatement(
                'DELETE FROM messenger_messages WHERE created_at < :cutoff',
                ['cutoff' => $threshold],
                ['cutoff' => Types::DATETIME_IMMUTABLE],
            );

            for ($seeded = 0; $seeded < self::ELIGIBLE_ROWS; ++$seeded) {
                $this->seed($connection, 'failed', $this->daysBefore($anchor, 90));
            }

            // One row per batch and no time to spend: the loop must end after its first pass with two rows
            // still eligible. Without the budget it would drain all three, which is precisely the unbounded
            // first run that would hold the advisory lock and the single-replica scheduler behind it.
            $pruner = new DbalFailedMessagePruner(
                $connection,
                new PostgresAdvisoryLock($connection),
                new NullLogger(),
                1,
                0,
            );

            $this->assertSame(1, $pruner->pruneFailedBefore($threshold), 'the drain stopped at its budget');
        });
    }

    private function pruner(Connection $connection): DbalFailedMessagePruner
    {
        return new DbalFailedMessagePruner(
            $connection,
            new PostgresAdvisoryLock($connection),
            new NullLogger(),
            self::SMALL_BATCH,
        );
    }

    private function seed(Connection $connection, string $queue, DateTimeImmutable $createdAt): string
    {
        $id = $connection->fetchOne(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) '
            . 'VALUES (:body, :headers, :queue, :createdAt, :createdAt) RETURNING id',
            [
                'body' => '{}',
                'headers' => '{}',
                'queue' => $queue,
                'createdAt' => $createdAt,
            ],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );
        $this->assertIsNumeric($id);

        return (string) $id;
    }

    private function daysBefore(DateTimeImmutable $anchor, int $days): DateTimeImmutable
    {
        return $anchor->sub(new DateInterval('P' . $days . 'D'));
    }

    private function countRowsForId(Connection $connection, string $id): int
    {
        $rowCount = $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE id = :id', ['id' => $id]);
        $this->assertIsNumeric($rowCount);

        return (int) $rowCount;
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
}
