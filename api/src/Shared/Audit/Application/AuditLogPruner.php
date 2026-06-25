<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditRetentionThreshold;

/**
 * Executes a retention deletion plan against the append-only `audit_log` — the **only** sanctioned delete
 * path on the table (FR9). The plan (one {@see AuditRetentionThreshold} per level) is produced by
 * {@see \Erpify\Shared\Audit\Domain\AuditRetentionPolicy}; this port just runs it. Deletion is idempotent
 * (every row matched is past its cutoff), so a missed or repeated run removes nothing it should not.
 */
interface AuditLogPruner
{
    /**
     * Deletes every `audit_log` row that falls before its level's cutoff; returns the number removed.
     */
    public function prune(AuditRetentionThreshold ...$plan): int;
}
