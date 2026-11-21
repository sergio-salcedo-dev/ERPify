<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditRetentionThreshold;

/**
 * Executes a retention deletion plan against the append-only `audit_log` — the **only** sanctioned delete
 * path on the table. The plan (one {@see AuditRetentionThreshold} per level) is produced by
 * {@see \Erpify\Shared\Audit\Domain\AuditRetentionPolicy}; this port just runs it. A row the plan exempts
 * from deletion past its cutoff is kept with its `ip` and `user_agent` cleared, so the window still bounds the
 * request metadata it carries. Both are idempotent (every row matched is past its cutoff), so a missed or
 * repeated run removes nothing it should not.
 */
interface AuditLogPruner
{
    /**
     * Deletes every `audit_log` row that falls before its level's cutoff and is not exempt, and clears the
     * request metadata of the exempt ones; returns the number removed.
     */
    public function prune(AuditRetentionThreshold ...$plan): int;
}
