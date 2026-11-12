<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Takes back the scans whose worker died or stalled past its lease.
 * Recurring, from {@see DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class ExpireScanLeasesMessage
{
}
