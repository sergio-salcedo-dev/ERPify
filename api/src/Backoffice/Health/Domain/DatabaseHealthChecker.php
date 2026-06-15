<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Health\Domain;

/**
 * Port answering whether the system of record is healthy. Implementations
 * probe the database with a trivial round-trip and never throw — a probe
 * failure is a health outcome (`false`), not an application error.
 */
interface DatabaseHealthChecker
{
    public function isHealthy(): bool;
}
