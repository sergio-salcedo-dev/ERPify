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

/**
 * {@link AuditLogPruner} backed by the `audit_log` table — the sole `DELETE` against the otherwise
 * append-only log (see {@see DbalAuditLogWriter}, which deliberately carries no delete path).
 *
 * Two production-shaping concerns, both for a write-heavy table rather than a measured hotspot (the app is
 * pre-production):
 * - **Chunked**: each level is drained in `id`-keyed batches (`DELETE … WHERE id IN (SELECT … LIMIT n)`), so
 *   no single statement holds a long lock or generates a large dead-tuple burst. Which index serves a batch
 *   depends on how much of the level is eligible, and `audit_log_level_idx (level, occurred_on)` serves
 *   neither regime. Measured on PostgreSQL 18 over 2M rows: a backfill purge (~1.2M eligible) is a plain
 *   `audit_log_pkey` scan, ~4 ms a batch, because ids are UUID v7 and so already in `occurred_on` order —
 *   the oldest ids ARE the eligible rows, which is the load-bearing property and the reason the batch needs
 *   no `(level, id)` index. The drain tail (~8k eligible) instead sorts an `audit_log_timeline_idx` scan,
 *   ~7 ms against ~2 ms unordered. So the cold-start/backfill case is the cheap one, not the exposure, and
 *   the ordering is paid for in the tail. A `(level, id)` index was measured and declined: with it present
 *   the planner chose it in neither regime.
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

        $parameters = [
            'level' => $threshold->level->value,
            'threshold' => $threshold->deleteBefore,
            'batch' => $this->batchSize,
        ];
        $types = ['threshold' => Types::DATETIMETZ_IMMUTABLE, 'batch' => Types::INTEGER];
        $exemption = '';

        // Composed rather than always bound: DBAL expands an empty list to `NOT IN (NULL)`, which is unknown
        // for every row and would silently stop the prune altogether. An empty exemption means no clause.
        if ([] !== $threshold->exemptActions) {
            $exemption = 'AND action NOT IN (:exemptActions) ';
            $parameters['exemptActions'] = $threshold->exemptActions;
            $types['exemptActions'] = ArrayParameterType::STRING;
        }

        do {
            // The exemption belongs to the INNER select and must stay there. On the outer `DELETE` the inner
            // would still take `batchSize` ids, fewer rows would delete, `$deleted !== $this->batchSize` would
            // end the loop early, and eligible rows would be left unpruned — silently, with no error.
            // `ORDER BY id` is what makes that reachable by a test at all: without it the batch is whatever
            // the planner returns, so the mutation is only probabilistically observable.
            //
            // `FOR UPDATE` is what makes the ordering say anything about LOCKS, and ordering alone says
            // nothing: the outer statement plans as `Nested Loop ← HashAggregate ← <subplan>`, and the
            // aggregate discards the subquery's order, so an unlocked ordered scan still hands its ids to a
            // probe that runs in hash order. Locking inside the subquery puts `LockRows` directly above the
            // ordered scan, so every lock is taken in `id` order — the order `DbalAuditSubjectRowLock` and
            // `DbalAuditActorAnonymiser` already impose — which is what keeps the prune from being the one
            // member of the closed set of three mutations that can deadlock against the other two.
            //
            // Its own cost, named because it is the same failure mode as the paragraph above: `FOR UPDATE`
            // under `LIMIT` re-checks a row after waiting on a concurrent writer, and a row that stops
            // matching drops out without a replacement — a short batch, and the drain ends early. That needs
            // a writer touching `level`, `occurred_on` or `action`. The table is append-only apart from the
            // two anonymisers, and neither writes any of the three.
            $deleted = (int) $this->connection->executeStatement(
                'DELETE FROM audit_log WHERE id IN ('
                . 'SELECT id FROM audit_log WHERE level = :level AND occurred_on < :threshold '
                . $exemption
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
