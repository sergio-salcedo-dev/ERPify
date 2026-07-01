<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance;

/**
 * Scheduler tick that triggers the GDPR subject-erasure integrity reconciliation. Carries no parameters —
 * the check is a full sweep of destroyed keys against their erasure evidence.
 */
final readonly class ReconcileSubjectErasuresMessage
{
}
