<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * GDPR "right to erasure" over the append-only audit trail — the second of the two sanctioned mutation
 * policies on `audit_log` (the retention pruner's DELETE is the first). It never deletes a row: it
 * rewrites every row of one subject so the trail stops being attributable to the person, while the
 * security evidence (action, level, occurred_on, resource, correlation) survives. See the GDPR policy in
 * docs/adr/audit-activity-log.md (D4).
 */
interface AuditActorAnonymiser
{
    /**
     * How many audit rows are currently attributed to $actorId, without mutating anything — the preview a
     * dry-run / pre-confirmation shows. Only approximate by the time {@see anonymise()} runs (rows may be
     * inserted or pruned in between); the authoritative figure is the erasure's own result.
     */
    public function countFor(string $actorId): int;

    /**
     * Irreversibly anonymises $actorId across the whole trail: one freshly minted UUID replaces it in
     * every matched row and `ip`/`user_agent` are redacted, in a single statement. Idempotent — a second
     * pass with the same original id matches nothing.
     */
    public function anonymise(string $actorId): ActorAnonymisationResult;
}
