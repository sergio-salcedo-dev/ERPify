<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Fixtures;

use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Override;
use RuntimeException;

/**
 * An {@see AuditLogWriter} standing in for an unreachable `audit_log`, scoped to the ONE action whose failure
 * the lockout-notice path swallows.
 *
 * Scoping by action rather than by level, mirroring {@see ThrowingLockoutAuditWriter}: this failure lives at
 * `AuditLevel::SECURITY`, and every other `security` write propagates by design. A double that threw for the
 * whole level would inject a fault into whatever else the sweep does before reaching this row, and the test
 * would fail pointing at the wrong collaborator.
 *
 * It counts its calls because the claim under test is a line ARRIVING: an arrival assertion over a write that
 * never happened would pass for the wrong reason, so the count is what separates "the report was discarded"
 * from "nothing was ever attempted".
 *
 * @internal
 */
final class ThrowingLockoutNoticeAuditWriter implements AuditLogWriter
{
    public const string NOTICE_ACTION = 'ACCOUNT_LOCKOUT_NOTIFIED';

    public int $attempts = 0;

    #[Override]
    public function write(AuditLogEntry $entry): void
    {
        if (self::NOTICE_ACTION !== $entry->action) {
            return;
        }

        ++$this->attempts;

        throw new RuntimeException('audit_log unavailable');
    }
}
