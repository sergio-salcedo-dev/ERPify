<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

/**
 * Scheduler tick that triggers the stored-identity inspection. Carries no parameters — the check is a full
 * sweep of the two columns the authentication path reads on every request, and a threshold would be a way of
 * tolerating some of what it finds.
 */
final readonly class InspectStoredIdentityMessage
{
}
