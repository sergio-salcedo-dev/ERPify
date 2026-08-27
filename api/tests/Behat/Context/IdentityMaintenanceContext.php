<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Step\When;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\NotifyLockedIdentitiesHandler;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\NotifyLockedIdentitiesMessage;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;

/**
 * Drives one of `identity_maintenance`'s scheduled jobs directly through its handler, the same shape
 * {@see EventStoreContext} already uses to drive a projection rebuild without the scheduler's own timing.
 *
 * The schedule mixes four jobs on independent cadences behind one Postgres-backed checkpoint
 * (see {@see \Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\IdentityMaintenanceSchedule}), so
 * which message a real tick of `scheduler_identity_maintenance` yields next is not something a scenario can
 * pin without becoming a test of the clock rather than of the handler. Invoking the handler in-process is
 * the real production entry point minus that timing dependency.
 */
final class IdentityMaintenanceContext extends AbstractContext
{
    public function __construct(private readonly NotifyLockedIdentitiesHandler $notifyLockedIdentities)
    {
    }

    #[When('the locked-identity notification sweep runs')]
    public function theLockedIdentityNotificationSweepRuns(): void
    {
        ($this->notifyLockedIdentities)(new NotifyLockedIdentitiesMessage());
    }
}
