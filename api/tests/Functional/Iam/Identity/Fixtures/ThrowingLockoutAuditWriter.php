<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Fixtures;

use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Override;
use RuntimeException;

/**
 * An {@see AuditLogWriter} standing in for an unreachable `audit_log`, scoped to the ONE action whose failure
 * the lockout path swallows.
 *
 * Scoping by action rather than by level, unlike its `activity` sibling: this failure lives at
 * `AuditLevel::SECURITY`, and every other `security` write propagates by design — the login-failure
 * projections among them. A double that threw for the whole level would inject a fault into the very request
 * that is meant to reach the lockout, and the test would fail inside authentication, pointing at the wrong
 * thing.
 *
 * It counts its calls because the claim under test is a line ARRIVING: an arrival assertion over a write that
 * never happened would pass for the wrong reason, so the count is what separates "the report was discarded"
 * from "nothing was ever attempted".
 *
 * @internal
 */
final class ThrowingLockoutAuditWriter implements AuditLogWriter
{
    public const string LOCKED_ACTION = 'USER_LOCKED';

    public int $attempts = 0;

    #[Override]
    public function write(AuditLogEntry $entry): void
    {
        if (self::LOCKED_ACTION !== $entry->action) {
            return;
        }

        ++$this->attempts;

        throw new RuntimeException('audit_log unavailable');
    }
}
