<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Health\Application;

use Erpify\Backoffice\Health\Domain\DatabaseHealthChecker;

/**
 * Reports whether the database backing the back office is reachable. The probe
 * itself lives behind the {@see DatabaseHealthChecker} port; this use case is the
 * orchestration seam the controller depends on.
 */
final readonly class CheckDatabaseHealth
{
    public function __construct(
        private DatabaseHealthChecker $checker,
    ) {
    }

    public function run(): bool
    {
        return $this->checker->isAvailable();
    }
}
