<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * Outbound port for the permanent, append-only `audit_log`. Write-only on purpose: forensic reads land in
 * a later epic directly against the table, so a `find`/`stream` method here would be surface with no
 * consumer. The single caller is the audit recording seam, which writes both `security` (before the
 * response is sent) and `activity` (on `kernel.terminate`, post-response) entries synchronously in the
 * request cycle — `change` rows reach the same port from the Doctrine `onFlush` listener.
 */
interface AuditLogWriter
{
    public function write(AuditLogEntry $entry): void;
}
