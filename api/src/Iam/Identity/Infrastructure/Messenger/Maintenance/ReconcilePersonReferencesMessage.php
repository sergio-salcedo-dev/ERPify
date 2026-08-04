<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

/**
 * Scheduler tick that triggers the person-reference reconciliation. Carries no parameters — the check is a
 * full sweep of every place the registry classifies as holding a person's id, and a threshold would be a way
 * of tolerating some of them.
 */
final readonly class ReconcilePersonReferencesMessage
{
}
