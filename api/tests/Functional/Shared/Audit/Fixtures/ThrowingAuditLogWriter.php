<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit\Fixtures;

use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\AuditLogWriter;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Override;
use RuntimeException;

/**
 * An {@see AuditLogWriter} standing in for an unreachable `audit_log`, scoped to the one level whose failure
 * is swallowed.
 *
 * The scoping is not tidiness. The same port also backs `writeSecurity()`, which propagates by design, and
 * the Doctrine `onFlush` capture of `change` rows, which propagates and rolls the business write back — so a
 * double that threw for every level would inject faults into two paths the test is not about. Today that is
 * harmless only because neither `User` nor `Session` opts into the audit CDC and the test authenticates
 * without HTTP; both are facts about other files, and a double that depends on them fails inside
 * authentication the day either changes, pointing at the wrong thing.
 *
 * It counts its calls because the claim under test is about a line ARRIVING, and an arrival assertion over a
 * write that never happened would pass for the wrong reason — the count is what makes the absence of the line
 * mean "discarded" rather than "never attempted".
 *
 * @internal
 */
final class ThrowingAuditLogWriter implements AuditLogWriter
{
    public int $attempts = 0;

    #[Override]
    public function write(AuditLogEntry $entry): void
    {
        if (AuditLevel::ACTIVITY !== $entry->level) {
            return;
        }

        ++$this->attempts;

        throw new RuntimeException('audit_log unavailable');
    }
}
