<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\IdentityMaintenanceSchedule;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\InspectStoredIdentityMessage;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\NotifyLockedIdentitiesMessage;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\PruneRetiredSessionsMessage;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\ReconcilePersonReferencesMessage;
use Erpify\Tests\Support\ScheduledTicks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @internal
 */
#[CoversClass(IdentityMaintenanceSchedule::class)]
final class IdentityMaintenanceScheduleTest extends TestCase
{
    #[Test]
    public function itSchedulesEveryMaintenanceTickAtItsOwnPeriod(): void
    {
        $schedule = (new IdentityMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // Both facts each tick encodes, because a count assertion goes green on either being wrong: it
        // survives the payload becoming another message and it survives `1 day` becoming `1 year`, which
        // are precisely the two ways this control silently stops being the control it is named after.
        //
        // It is also the ONLY red for the wiring of the three ticks that mint no transport of their own.
        // Folding a tick into an existing schedule adds no transport, so `make php.lint.schedule-consumption`
        // stays green whether the message is registered here or not, and nothing else in the tree would
        // notice its removal.
        //
        // The periods differ on purpose: five minutes tracks a lock that lives fifteen, while a daily sweep
        // would meet a given lockout only by coincidence. Two of the others observe durable state, and the
        // session prune is daily because its windows are 30 and 90 days — a finer cadence would re-run a
        // DELETE that matched nothing.
        $this->assertSame(
            [
                // Sorted by the helper, so the order here is alphabetical and not the declaration order.
                InspectStoredIdentityMessage::class . ' @ every 1 day',
                NotifyLockedIdentitiesMessage::class . ' @ every 5 minutes',
                PruneRetiredSessionsMessage::class . ' @ every 1 day',
                ReconcilePersonReferencesMessage::class . ' @ every 1 day',
            ],
            ScheduledTicks::describe($schedule),
        );
    }

    #[Test]
    public function itPersistsItsCheckpointSoAPeriodLongerThanTheWorkerCanElapse(): void
    {
        $schedule = (new IdentityMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // Without state the checkpoint is process memory: the first run date is `boot + 1 day`, and both
        // workers are replaced hourly by `--time-limit=3600` + `restart: unless-stopped`, so the tick is
        // permanently further away than the process meant to emit it and a daily schedule never fires —
        // with the compose gate, deptrac and every test still green. This assertion is the only thing in
        // the suite that goes red on it.
        $this->assertInstanceOf(
            \Symfony\Contracts\Cache\CacheInterface::class,
            $schedule->getState(),
            'The schedule must be stateful, or its daily period never comes due under a time-limited worker.',
        );
    }
}
