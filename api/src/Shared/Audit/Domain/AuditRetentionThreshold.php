<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use DateTimeImmutable;

/**
 * One line of the retention deletion plan: rows of {@see $level} whose `occurred_on` is before
 * {@see $deleteBefore} are out of retention, **except** those whose `action` is in {@see $exemptActions}.
 * The plan is data — {@see AuditRetentionPolicy} produces it and the pruner executes it — so the per-level
 * decision never leaks into the messaging/orchestration layer as control flow, and the exemption is a
 * domain value the adapter binds rather than a compliance literal spelled inside its SQL.
 *
 * **The exemption rides every line, not only the one whose level holds the evidence today.** Scoping it to
 * `security` would reopen the hole the day an evidence row is written at another level, silently and with
 * nothing red — and filtering on levels that never carry those actions costs nothing, since the predicate
 * simply matches no row.
 */
final readonly class AuditRetentionThreshold
{
    /**
     * @param list<string> $exemptActions
     */
    public function __construct(
        public AuditLevel $level,
        public DateTimeImmutable $deleteBefore,
        public array $exemptActions = [],
    ) {
    }
}
