<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Deletes committed upload intents past their retention window. Recurring, from {@see DocumentsMaintenanceSchedule};
 * carries nothing.
 */
final readonly class PruneCommittedIntentsMessage
{
}
