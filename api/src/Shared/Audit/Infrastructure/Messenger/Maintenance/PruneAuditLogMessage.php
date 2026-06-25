<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance;

/**
 * Scheduler tick that triggers the differentiated retention prune of `audit_log`. The two windows are
 * parametrizable here and `security` outlives `activity` (the {@see \Erpify\Shared\Audit\Domain\AuditRetentionPolicy}
 * invariant): activity rows are operational noise that can age out in a quarter, while the security trail
 * (denials, access) is kept a year to satisfy the legal retention duty.
 */
final readonly class PruneAuditLogMessage
{
    public function __construct(
        public int $activityRetentionDays = 90,
        public int $securityRetentionDays = 365,
    ) {
    }
}
