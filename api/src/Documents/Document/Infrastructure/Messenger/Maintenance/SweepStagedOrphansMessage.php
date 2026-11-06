<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Deletes staged objects no document row and no live intent owns. Recurring, from {@see DocumentsMaintenanceSchedule};
 * carries nothing.
 */
final readonly class SweepStagedOrphansMessage
{
}
