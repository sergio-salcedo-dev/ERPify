<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use DateInterval;
use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditRetentionPolicy;

/**
 * The differentiated privacy-ceiling windows for the audit levels that carry one: how long a row of that
 * level survives in `audit_log` before the pruner may delete it. The legal separation between the activity
 * and security axes is encoded as an invariant — `security` must strictly outlive `activity` — so a
 * misconfigured schedule fails loudly at construction rather than silently over-pruning the security trail.
 *
 * `change` rows are regulatory compliance evidence governed by a retention floor, not a privacy ceiling, so
 * the plan carries no cutoff for them and the pruner never deletes a change row on this axis. The policy
 * owns the per-level decision: {@see thresholdsAt()} turns "now" into the deletion plan (one
 * {@see AuditRetentionThreshold} per ceiling-bearing level), so the storage policy is expressed as data
 * here, not as a level loop in the message handler. Pure value object — the caller passes the instant,
 * keeping the plan unit-testable against a fixed point.
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
            $days = $this->ceilingDaysFor($level);

            if (null === $days) {
                continue;
            }

            $plan[] = new AuditRetentionThreshold($level, $now->sub(new DateInterval('P' . $days . 'D')));
        }

        return $plan;
    }

    private function ceilingDaysFor(AuditLevel $level): ?int
    {
        return match ($level) {
            AuditLevel::ACTIVITY => $this->activityRetentionDays,
            AuditLevel::SECURITY => $this->securityRetentionDays,
            AuditLevel::CHANGE => null,
        };
    }
}
