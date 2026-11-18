<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Deletes the sealed objects of `Erased` documents (the single owner of that deletion). Recurring, from {@see
 * DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class DeleteErasedObjectsMessage
{
}
