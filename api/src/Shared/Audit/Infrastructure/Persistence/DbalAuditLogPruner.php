<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Audit\Application\AuditLogPruner;
use Erpify\Shared\Audit\Domain\AuditRetentionThreshold;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * {@link AuditLogPruner} backed by the `audit_log` table — the sole `DELETE` against the otherwise
 * append-only log (see {@see DbalAuditLogWriter}, which deliberately carries no delete path).
 *
 * Two production-shaping concerns, both for a write-heavy table rather than a measured hotspot (the app is
 * pre-production):
 * - **Chunked**: each level is drained in `id`-keyed batches (`DELETE … WHERE id IN (SELECT … LIMIT n)`), so
 *   no single statement holds a long lock or generates a large dead-tuple burst. What a batch costs turns on
 *   one premise — whether `id` order tracks `occurred_on` order — and `audit_log_level_idx
 *   (level, occurred_on)` served no regime measured. UUID v7 orders by **mint** time, so the two coincide
 *   only while rows are written as they occur. Measured on PostgreSQL 18 over 2M rows seeded that way: a
 *   large sweep (~1.2M eligible) is an `audit_log_pkey` scan at ~4 ms a batch, because the oldest ids ARE
 *   the eligible rows; the drain tail (~8k eligible) sorts an `audit_log_timeline_idx` scan instead, ~7 ms
 *   against ~2 ms unordered, which is where the ordering is paid for in the steady state.
 * - **A backfill breaks that premise, and it — not the steady state — is the exposure.** Historical rows
 *   imported behind a prefix of recent ineligible ones hold the largest ids and the smallest `occurred_on`,
 *   so the ascending scan crosses the whole ineligible prefix on every batch. Measured with 1.5M eligible
 *   behind 500k ineligible: ~134 ms a batch, discarding 500k rows each time, against ~4 ms correlated.
 *   Ordering on `(occurred_on, id)` would fix it and `audit_log_timeline_idx` would serve it — and it is
 *   refused, because the prune would stop acquiring in the `id` order the erasure paths use and the deadlock
 *   comes back. The backfill cost is the price of the lock order, paid deliberately. A `(level, id)` index
 *   was built and measured in both regimes and declined in both: the planner chose it in neither.
 * - **Serialised**: the whole sweep runs under one session-level advisory lock, so a second prune (e.g. an
 *   accidentally-scaled scheduler) is skipped rather than racing — defence in depth, not a correctness
 *   need (the delete is idempotent and prod runs a single-replica scheduler).
 *
 * Owns no transaction and does not swallow database failures. Each run logs its outcome — rows pruned, or
 * skipped because another worker already holds the lock — so a perpetually-held lock is visible rather than
 * silently stopping retention.
 */
#[AsAlias(AuditLogPruner::class)]
final readonly class DbalAuditLogPruner implements AuditLogPruner
{
    private const string LOCK_NAME = 'audit_log_retention_prune';

    private const int DEFAULT_BATCH_SIZE = 5000;

    public function __construct(
        private Connection $connection,
        private PostgresAdvisoryLock $advisoryLock,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException(
                \sprintf('Audit prune batch size must be at least 1, got %d.', $batchSize),
            );
        }
    }

    #[Override]
    public function prune(AuditRetentionThreshold ...$plan): int
    {
        $removed = 0;

        $ran = $this->advisoryLock->withTryLock(self::LOCK_NAME, function () use ($plan, &$removed): void {
            foreach ($plan as $threshold) {
                $removed += $this->drainLevel($threshold);
            }
        });

        if (!$ran) {
            $this->logger->info('Audit retention prune skipped: another worker holds the lock.');

            return $removed;
        }

        $this->logger->info('Pruned {removed} audit_log row(s) past retention.', ['removed' => $removed]);

        return $removed;
    }

    private function drainLevel(AuditRetentionThreshold $threshold): int
    {
        $removed = 0;

        // Bound unconditionally, and `AuditRetentionThreshold` is what makes that safe: it refuses an empty
        // exemption at construction, so the one input that would render this placeholder as the literal
        // `NULL` — unknown for every row, and a prune that deletes nothing while reporting success — cannot
        // reach here. Composing the clause instead would restate that guarantee in this adapter, on a branch
        // no caller can enter and therefore no test can pin.
        $parameters = [
            'level' => $threshold->level->value,
            'threshold' => $threshold->deleteBefore,
            'batch' => $this->batchSize,
            'exemptActions' => $threshold->exemptActions,
        ];
        $types = [
            'threshold' => Types::DATETIMETZ_IMMUTABLE,
            'batch' => Types::INTEGER,
            'exemptActions' => ArrayParameterType::STRING,
        ];

        do {
            // The exemption belongs to the INNER select and must stay there. On the outer `DELETE` the inner
            // would still take `batchSize` ids, fewer rows would delete, `$deleted !== $this->batchSize` would
            // end the loop early, and eligible rows would be left unpruned — silently, with no error.
            // `ORDER BY id` is what makes that reachable by a test at all: without it the batch is whatever
            // the planner returns, so the mutation is only probabilistically observable.
            //
            // `FOR UPDATE` is what makes the ordering say anything about LOCKS, and ordering alone says
            // nothing: `LIMIT` blocks sublink pull-up, so the outer statement unique-ifies the subquery
            // through a blocking node — a `HashAggregate` in every plan observed — which discards its order,
            // and an unlocked ordered scan hands its ids to a probe running in hash order. Locking inside
            // the subquery puts `LockRows` directly above the ordered scan and below that node, so the whole
            // lock set is acquired ascending by `id` before the outer statement probes anything. That is the
            // order `DbalAuditActorAnonymiser` imposes on itself and `DbalAuditSubjectRowLock` imposes on
            // the resource pass, which orders nothing of its own — so the prune stops being the member of
            // the closed set of three mutations that can deadlock against the other two. Which node does the
            // unique-ification is a costed choice, not a contract; the conclusion holds for any of them,
            // because all of them block on the subplan before the outer statement takes a lock. Measured at
            // batch 5000: ~9.9 ms against ~9.3 ms, and ~17% more buffer touches, plus one `XLOG_HEAP_LOCK`
            // per row on top of the delete record.
            //
            // Its own cost, named because it is the same failure mode as the paragraph above: `FOR UPDATE`
            // under `LIMIT` re-checks a row after waiting on a concurrent writer, and a row that stops
            // matching — or that the waited-on transaction deleted — drops out without a replacement. That
            // is a short batch, and the drain ends early. It needs a concurrent writer touching `level`,
            // `occurred_on` or `action`, or a concurrent deleter. Neither anonymiser writes any of the three
            // and `DbalAuditLogWriter` only inserts, so within `src` the sole deleter is this class, which
            // `PostgresAdvisoryLock` serialises against another session running it. That lock excludes
            // nothing else: a migration, a test or an operator deleting rows by hand concurrently would end
            // a sweep early and silently.
            $deleted = (int) $this->connection->executeStatement(
                'DELETE FROM audit_log WHERE id IN ('
                . 'SELECT id FROM audit_log WHERE level = :level AND occurred_on < :threshold '
                . 'AND action NOT IN (:exemptActions) '
                . 'ORDER BY id LIMIT :batch FOR UPDATE'
                . ')',
                $parameters,
                $types,
            );
            $removed += $deleted;
        } while ($deleted === $this->batchSize);

        return $removed;
    }
}
