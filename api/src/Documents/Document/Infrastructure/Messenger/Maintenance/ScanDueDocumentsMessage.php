<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Runs one pass of the scan sweep: the authoritative driver of every scan, of which the wake-up message is only an
 * early start.
 * Recurring, from {@see DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class ScanDueDocumentsMessage
{
}
