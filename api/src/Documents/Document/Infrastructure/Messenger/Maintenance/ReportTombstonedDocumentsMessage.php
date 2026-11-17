<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * The Document-driven tombstone reconciler: alarms on a row that is not `Erased` whose key is destroyed. Recurring,
 * from {@see DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class ReportTombstonedDocumentsMessage
{
}
