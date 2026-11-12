<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Ends in `ScanFailed` the `Received` documents found at or above a lowered cap.
 * Recurring, from {@see DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class SweepCappedDocumentsMessage
{
}
