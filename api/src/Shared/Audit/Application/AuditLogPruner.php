<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\AuditLevel;

/**
 * Deletes out-of-retention rows from the append-only `audit_log`. This is the **only** sanctioned delete
 * path on the table (FR9); every other access is append or read. Pruning by `(level, threshold)` is
 * idempotent — re-running it over the same window removes nothing new — so a missed or double tick is
 * harmless. The differentiated windows live in {@see \Erpify\Shared\Audit\Domain\AuditRetentionPolicy};
 * this port only takes the resolved threshold for one level.
 */
interface AuditLogPruner
{
    /**
     * Deletes rows of the given level whose `occurred_on` is strictly before the threshold; returns the
     * number of rows removed.
     */
    public function pruneOlderThan(AuditLevel $level, DateTimeImmutable $threshold): int;
}
