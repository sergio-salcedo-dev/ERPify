<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The differentiated retention windows for {@see AuditLevel}: how long a row of each level survives in
 * `audit_log` before the pruner is allowed to delete it. The legal separation between the two axes is
 * encoded as an invariant — `security` must outlive `activity` — so a misconfigured schedule fails loudly
 * at construction rather than silently over-pruning the security trail.
 *
 * The windows come from trusted operational config (the scheduled message's defaults), never from client
 * input, so a breach is a programming/config fault: a plain {@see InvalidArgumentException}, kept out of
 * the RFC 9457 mapping on purpose. Pure value object — no clock, no framework; the caller passes the
 * instant so the per-level threshold stays unit-testable against a fixed point.
 */
final readonly class AuditRetentionPolicy
{
    public function __construct(
        private int $activityRetentionDays,
        private int $securityRetentionDays,
    ) {
        if ($activityRetentionDays < 1 || $securityRetentionDays < 1) {
            throw new InvalidArgumentException('Audit retention windows must be at least one day.');
        }

        if ($securityRetentionDays <= $activityRetentionDays) {
            throw new InvalidArgumentException('Audit security retention window must exceed the activity window.');
        }
    }

    /**
     * The instant before which rows of the given level are out of retention and may be pruned.
     */
    public function thresholdFor(AuditLevel $level, DateTimeImmutable $now): DateTimeImmutable
    {
        $days = match ($level) {
            AuditLevel::ACTIVITY => $this->activityRetentionDays,
            AuditLevel::SECURITY => $this->securityRetentionDays,
        };

        return $now->sub(new DateInterval('P' . $days . 'D'));
    }
}
