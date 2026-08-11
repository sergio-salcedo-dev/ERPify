<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Application\NotifyLockedIdentities;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the lockout-notification sweep on each scheduled tick. A pure edge: the sweep owns the policy, the
 * per-row failure boundary and the clock, so there is nothing left here to decide.
 */
#[AsMessageHandler]
final readonly class NotifyLockedIdentitiesHandler
{
    public function __construct(private NotifyLockedIdentities $notifier)
    {
    }

    public function __invoke(NotifyLockedIdentitiesMessage $message): void
    {
        unset($message);

        $this->notifier->notifyLockedOwners();
    }
}
