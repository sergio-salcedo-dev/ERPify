<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

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
 * - **Chunked**: each level is drained in `id`-keyed batches (`DELETE … WHERE id IN (SELECT … LIMIT n)`)
 *   served by the `audit_log_level_idx (level, occurred_on)` index, so no single statement holds a long
 *   lock or generates a large dead-tuple burst — the exposure is a cold-start/backfill purge, not the
 *   ~one-day steady state.
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

        do {
            $deleted = (int) $this->connection->executeStatement(
                'DELETE FROM audit_log WHERE id IN ('
                . 'SELECT id FROM audit_log WHERE level = :level AND occurred_on < :threshold LIMIT :batch'
                . ')',
                [
                    'level' => $threshold->level->value,
                    'threshold' => $threshold->deleteBefore,
                    'batch' => $this->batchSize,
                ],
                ['threshold' => Types::DATETIMETZ_IMMUTABLE, 'batch' => Types::INTEGER],
            );
            $removed += $deleted;
        } while ($deleted === $this->batchSize);

        return $removed;
    }
}
