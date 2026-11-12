<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Reports the documents the scan leaves waiting on something it cannot repair itself. Recurring, from
 * {@see DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class ReportDocumentScansMessage
{
}
