<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Erpify\Shared\Event\Infrastructure\Messenger\DbalFailedMessagePruner;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(DbalFailedMessagePruner::class)]
final class DbalFailedMessagePrunerTest extends TestCase
{
    #[Test]
    #[DataProvider('provideItRejectsANonPositiveBatchSizeCases')]
    public function itRejectsANonPositiveBatchSize(int $batchSize): void
    {
        // At a batch of zero the drain loop never ends: `DELETE … LIMIT 0` removes nothing, and its
        // `0 === 0` exit condition reads that as a full batch — so the sweep spins forever holding the
        // advisory lock, which is worse than not pruning at all. A negative batch is a Postgres error
        // raised after the lock is taken, on every tick, for ever.
        $this->expectException(InvalidArgumentException::class);

        new DbalFailedMessagePruner(
            $this->createStub(Connection::class),
            new PostgresAdvisoryLock($this->createStub(Connection::class)),
            new NullLogger(),
            $batchSize,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideItRejectsANonPositiveBatchSizeCases(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-1];
    }

    #[Test]
    public function itKeepsDrainingAfterABatchThatCameBackShort(): void
    {
        // A batch is short when `FOR UPDATE` re-checks a row a concurrent writer changed and it drops out
        // with no replacement — the clause's own documented cost, so the case is guaranteed to exist rather
        // than hypothetical. An exit keyed to "the batch was full" reads that as "nothing left" and walks
        // away from the rest of the backlog; only an empty pass means empty. Staged here rather than
        // functionally because the trigger is concurrency, which a single-connection test cannot arrange.
        $batches = [2, 1, 3, 0];

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(true);
        $connection->method('executeStatement')->willReturnCallback(
            static function () use (&$batches): int {
                return \array_shift($batches) ?? 0;
            },
        );

        $removed = (new DbalFailedMessagePruner(
            $connection,
            new PostgresAdvisoryLock($connection),
            new NullLogger(),
            2,
        ))->pruneFailedBefore(new DateTimeImmutable('-30 days'));

        $this->assertSame(6, $removed, 'the drain stopped at the short batch and left rows behind');
        $this->assertSame([], $batches, 'the drain did not run to the empty pass');
    }

    #[Test]
    public function itRejectsANegativeDrainBudget(): void
    {
        // Zero is legitimate — one batch per tick, which is how a drain is throttled to a crawl on purpose.
        // A negative budget is not a slower drain, it is no drain at all: the loop stops after its first
        // batch for ever, and the table it exists to bound grows behind a job that reports success.
        $this->expectException(InvalidArgumentException::class);

        new DbalFailedMessagePruner(
            $this->createStub(Connection::class),
            new PostgresAdvisoryLock($this->createStub(Connection::class)),
            new NullLogger(),
            1,
            -1,
        );
    }
}
