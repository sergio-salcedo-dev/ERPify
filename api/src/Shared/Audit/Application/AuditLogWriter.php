<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

/**
 * Outbound port for the permanent, append-only `audit_log`. Write-only on purpose: forensic reads land in
 * a later epic directly against the table, so a `find`/`stream` method here would be surface with no
 * consumer. The single caller is the audit recording seam, which inserts a `security` entry synchronously
 * before the response is sent and an `activity` entry from the worker.
 */
interface AuditLogWriter
{
    public function write(AuditLogEntry $entry): void;
}
