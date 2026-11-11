<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Reports the keystore growth line and alarms on objects and rows that lost each other.
 * Recurring, from {@see DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class ReportDocumentStorageMessage
{
}
