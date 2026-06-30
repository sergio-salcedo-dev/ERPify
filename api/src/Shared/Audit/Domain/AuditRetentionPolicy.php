<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use DateInterval;
use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditRetentionPolicy;

/**
 * The differentiated retention windows for the audit levels. `activity` and `security` carry a privacy
 * *ceiling* — how long a row survives in `audit_log` before the pruner may delete it — and the legal
 * separation between them is encoded as an invariant: `security` must strictly outlive `activity`, so a
 * misconfigured schedule fails loudly at construction rather than silently over-pruning the security trail.
 *
 * `change` rows are regulatory compliance evidence governed by a *floor* rather than a ceiling: they become
 * prune-eligible only once past the legal minimum ({@see self::COMPLIANCE_RETENTION_FLOOR}), and are never
 * deleted earlier. The policy owns the per-level decision: {@see thresholdsAt()} turns "now" into the
 * deletion plan (one {@see AuditRetentionThreshold} per level), so the storage policy is expressed as data
 * here, not as a level loop in the message handler. Pure value object — the caller passes the instant,
 * keeping the plan unit-testable against a fixed point.
 */
final readonly class AuditRetentionPolicy
{
    /**
     * The legal minimum a `change` (compliance) row is retained before becoming prune-eligible. A floor,
     * not a privacy ceiling: the row is never deleted earlier, and erasure of personal data within the
     * floor is a crypto-shredding concern, not a deletion one, so append-only integrity is preserved.
     */
    private const string COMPLIANCE_RETENTION_FLOOR = 'P5Y';

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
     * out of retention — its privacy ceiling for `activity`/`security`, its compliance floor for `change`.
     *
     * @return list<AuditRetentionThreshold>
     */
    public function thresholdsAt(DateTimeImmutable $now): array
    {
        $plan = [];

        foreach (AuditLevel::cases() as $level) {
            $plan[] = new AuditRetentionThreshold($level, $now->sub($this->retentionWindowFor($level)));
        }

        return $plan;
    }

    private function retentionWindowFor(AuditLevel $level): DateInterval
    {
        return match ($level) {
            AuditLevel::ACTIVITY => new DateInterval('P' . $this->activityRetentionDays . 'D'),
            AuditLevel::SECURITY => new DateInterval('P' . $this->securityRetentionDays . 'D'),
            AuditLevel::CHANGE => new DateInterval(self::COMPLIANCE_RETENTION_FLOOR),
        };
    }
}
