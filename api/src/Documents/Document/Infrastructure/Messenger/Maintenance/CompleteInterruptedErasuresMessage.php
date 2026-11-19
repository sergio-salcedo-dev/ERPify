<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Finishes the erasures the ledger holds and the database does not. Recurring, from {@see
 * DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class CompleteInterruptedErasuresMessage
{
}
