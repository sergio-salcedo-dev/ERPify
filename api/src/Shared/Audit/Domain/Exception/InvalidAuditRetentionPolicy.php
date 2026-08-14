<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Thrown when an {@see \Erpify\Shared\Audit\Domain\AuditRetentionPolicy} is built with windows that break
 * the legal separation between the audit axes — a non-positive window, or a `security` window that does
 * not strictly outlive `activity` — or when a line of the plan it produces is not a deletion instruction
 * anyone may execute.
 *
 * Deliberately marker-less: the windows come from trusted operational config (the scheduled message's
 * defaults), so a breach is a server-side configuration fault, not bad client input. Leaving it unmarked
 * keeps it out of the RFC 9457 4xx mapping and surfaces it in Sentry as an actionable error — the same
 * reasoning as the sibling {@see InvalidAuditLogEntry}.
 */
final class InvalidAuditRetentionPolicy extends DomainException
{
    public const string TYPE = 'invalid-audit-retention-policy';

    public static function windowMustBeAtLeastOneDay(): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit retention windows must be at least one day.',
        );
    }

    public static function securityMustOutliveActivity(int $activityDays, int $securityDays): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit security retention window must strictly exceed the activity window.',
            context: ['activityDays' => $activityDays, 'securityDays' => $securityDays],
        );
    }

    public static function exemptActionsMustNotBeEmpty(string $level): self
    {
        return new self(
            type: self::TYPE,
            title: 'Audit retention threshold must exempt at least one action.',
            context: ['level' => $level],
        );
    }
}
