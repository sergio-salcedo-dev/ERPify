<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use DateInterval;
use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditRetentionPolicy;

/**
 * The differentiated retention windows for {@see AuditLevel}: how long a row of each level survives in
 * `audit_log` before the pruner may delete it. The legal separation between the two axes is encoded as an
 * invariant — `security` must strictly outlive `activity` — so a misconfigured schedule fails loudly at
 * construction rather than silently over-pruning the security trail.
 *
 * The policy owns the per-level decision: {@see thresholdsAt()} turns "now" into the full deletion plan
 * (one {@see AuditRetentionThreshold} per level), so the storage policy is expressed as data here, not as
 * a level loop in the message handler. Pure value object — the caller passes the instant, keeping the plan
 * unit-testable against a fixed point.
 */
final readonly class AuditRetentionPolicy
{
    public function __construct(
        private int $activityRetentionDays,
        private int $securityRetentionDays,
    ) {
        if ($activityRetentionDays < 1 || $securityRetentionDays < 1) {
            throw InvalidAuditRetentionPolicy::windowMustBeAtLeastOneDay();
        }

        if ($securityRetentionDays <= $activityRetentionDays) {
            throw InvalidAuditRetentionPolicy::securityMustOutliveActivity(
                $activityRetentionDays,
                $securityRetentionDays,
            );
        }
    }

    /**
     * The full deletion plan at the given instant: for every level, the cutoff before which its rows are
     * out of retention.
     *
     * @return list<AuditRetentionThreshold>
     */
    public function thresholdsAt(DateTimeImmutable $now): array
    {
        $plan = [];

        foreach (AuditLevel::cases() as $level) {
            $plan[] = new AuditRetentionThreshold($level, $this->deleteBeforeFor($level, $now));
        }

        return $plan;
    }

    private function deleteBeforeFor(AuditLevel $level, DateTimeImmutable $now): DateTimeImmutable
    {
        $days = match ($level) {
            AuditLevel::ACTIVITY => $this->activityRetentionDays,
            AuditLevel::SECURITY => $this->securityRetentionDays,
        };

        return $now->sub(new DateInterval('P' . $days . 'D'));
    }
}
