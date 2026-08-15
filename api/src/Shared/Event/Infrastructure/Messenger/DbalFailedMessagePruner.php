<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Event\Application\FailedMessagePruner;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link FailedMessagePruner} backed by the Doctrine transport's own table.
 *
 * **`queue_name` is not a filter, it is the safety property of this class.** `async` and `failed` are one
 * physical table (`messenger_messages`) told apart by that column alone — both DSNs are
 * `doctrine://default?queue_name=…` and only one migration ever creates a Messenger table. A statement here
 * that forgets the predicate does not prune a dead letter early, it deletes messages that are in flight, and
 * nothing downstream would report their absence. The name is a constant rather than an inline literal so the
 * gate test can compare it against `config/packages/messenger.yaml`: a DSN edit that renames the queue would
 * otherwise leave this class pruning nothing at all, silently and in the safe direction, which is exactly the
 * kind of quiet no-op this batch of work exists to refuse.
 *
 * **It batches under an advisory lock, like the audit pruner and unlike the dedup one.** That is the standard
 * mechanism for the single-executor invariant (I1) in `docs/adr/maintenance-job-execution-contract.md`, and
 * this job mutates state, so it is not among the read-only jobs the invariant exempts. Its own lock name is
 * the second live one in the tree; `PostgresAdvisoryLock` derives its key through `hashtext`, whose 32-bit
 * space is stated on that class — with two names the collision risk is negligible arithmetic, but the
 * retrofit trigger recorded there is now genuinely pulled rather than hypothetical.
 *
 * **`ORDER BY id … FOR UPDATE` is the audit pruner's discipline and it carries over for the same reason.**
 * `LIMIT` blocks sublink pull-up, so the outer `DELETE` unique-ifies the subquery through a blocking node that
 * discards its order and probes in that node's order; ordering without the lock therefore orders nothing.
 * Locking inside the subquery puts `LockRows` above the ordered scan, so the whole set is taken ascending by
 * `id`. Here the contended writer is the transport itself — Symfony's Doctrine receiver takes its own row
 * locks to claim and to ack — so the ordering is what keeps a drain from meeting a live consumer head-on.
 *
 * Its cost is the same one the audit pruner names: `FOR UPDATE` under `LIMIT` re-checks a row after waiting on
 * a concurrent writer, and one that stops matching drops out without a replacement. That is a short batch and
 * an early end to the drain, which the next tick picks up.
 *
 * **The drain is bounded by wall-clock, not only by running out of rows, and the audit pruner's shape is the
 * reason it needs to be.** That table has always been pruned, so its sweep only ever meets one day of arrivals;
 * this one arrives to a queue nothing has ever bounded, so its FIRST run faces the entire history. A run holds
 * the advisory lock for its whole duration and `messenger:consume --time-limit=3600` cannot interrupt a handler
 * already in flight, so an unbounded first drain would hold the single-replica scheduler against every other
 * tick queued behind it. Past the budget it stops early and tomorrow continues — the table shrinks over days
 * instead of one run owning the worker.
 *
 * **Its own log lines are `info`, and that is a deliberate limit rather than observability.** Prod routes
 * Monolog through `fingers_crossed` at `action_level: error`, so neither the skip nor the summary is readable
 * there; they are for a developer with the dev log open. What reports a prune that has stopped running is not
 * a line at all — it is the backlog itself, which `ReportDeadLetterBacklogHandler` alarms on hourly at `error`
 * from the tick beside this one.
 *
 * **What a prune destroys, stated because `event_store` does not cover it.** The domain event survives: it was
 * appended before dispatch, atomically with the aggregate write. What lives only in `messenger_messages.headers`
 * is the failure context — the exception class and message, the retry count, the redelivery stamp — and it goes
 * with the row. Past the window this class decides that a failure nobody triaged is not going to be, and the
 * evidence of WHY it failed is the price.
 */
#[AsAlias(FailedMessagePruner::class)]
final readonly class DbalFailedMessagePruner implements FailedMessagePruner
{
    /**
     * The `queue_name` the failure transport writes, mirrored from `config/packages/messenger.yaml`.
     */
    public const string FAILED_QUEUE = 'failed';

    private const string LOCK_NAME = 'failed_transport_retention_prune';

    private const int DEFAULT_BATCH_SIZE = 5000;

    private const int DEFAULT_DRAIN_BUDGET_SECONDS = 300;

    public function __construct(
        private Connection $connection,
        private PostgresAdvisoryLock $advisoryLock,
        private LoggerInterface $logger,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private int $drainBudgetSeconds = self::DEFAULT_DRAIN_BUDGET_SECONDS,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException(
                \sprintf('Failed-transport prune batch size must be at least 1, got %d.', $batchSize),
            );
        }

        if ($drainBudgetSeconds < 0) {
            throw new InvalidArgumentException(
                \sprintf('Failed-transport drain budget must not be negative, got %d.', $drainBudgetSeconds),
            );
        }
    }

    #[Override]
    public function pruneFailedBefore(DateTimeImmutable $threshold): int
    {
        $removed = 0;

        $ran = $this->advisoryLock->withTryLock(self::LOCK_NAME, function () use ($threshold, &$removed): void {
            $removed = $this->drain($threshold);
        });

        if (!$ran) {
            $this->logger->info('Failed-transport prune skipped: another worker holds the lock.');

            return $removed;
        }

        $this->logger->info('Pruned {removed} failed message(s) past retention.', ['removed' => $removed]);

        return $removed;
    }

    private function drain(DateTimeImmutable $threshold): int
    {
        $removed = 0;
        $startedAt = \hrtime(true);
        $parameters = [
            'queue' => self::FAILED_QUEUE,
            'threshold' => $threshold,
            'batch' => $this->batchSize,
        ];
        $types = [
            'threshold' => Types::DATETIME_IMMUTABLE,
            'batch' => Types::INTEGER,
        ];

        do {
            $deleted = (int) $this->connection->executeStatement(
                'DELETE FROM messenger_messages WHERE id IN ('
                . 'SELECT id FROM messenger_messages WHERE queue_name = :queue AND created_at < :threshold '
                . 'ORDER BY id LIMIT :batch FOR UPDATE'
                . ')',
                $parameters,
                $types,
            );
            $removed += $deleted;
        } while ($deleted === $this->batchSize && $this->withinBudget($startedAt));

        return $removed;
    }

    /**
     * Monotonic on purpose: this bounds elapsed work, not a point in time, so a clock the operator or NTP
     * moves must not be able to shorten or extend it — which is also why it does not reach for the injected
     * {@see \Erpify\Shared\Clock\Domain\Clock}, whose whole job is to be movable.
     */
    private function withinBudget(float|int $startedAt): bool
    {
        return (\hrtime(true) - $startedAt) / 1e9 < $this->drainBudgetSeconds;
    }
}
