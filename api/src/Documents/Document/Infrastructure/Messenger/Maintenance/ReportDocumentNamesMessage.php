<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Reports the documents whose sealed original filename no longer opens. Recurring, from
 * {@see DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class ReportDocumentNamesMessage
{
}
