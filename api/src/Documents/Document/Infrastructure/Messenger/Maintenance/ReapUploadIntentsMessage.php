<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Ends the upload intents that can no longer become a document (the reaper). Recurring, from {@see
 * DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class ReapUploadIntentsMessage
{
}
