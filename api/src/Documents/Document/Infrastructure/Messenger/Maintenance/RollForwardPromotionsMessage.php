<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * Finishes the promotion of committed documents whose writer died before it. Recurring, from {@see
 * DocumentsMaintenanceSchedule}; carries nothing.
 */
final readonly class RollForwardPromotionsMessage
{
}
