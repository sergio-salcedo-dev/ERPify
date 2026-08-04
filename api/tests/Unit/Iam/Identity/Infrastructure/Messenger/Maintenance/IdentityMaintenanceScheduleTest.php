<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\IdentityMaintenanceSchedule;
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
    public function itSchedulesThePersonReferenceReconciliationDaily(): void
    {
        $schedule = (new IdentityMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // Both facts the schedule encodes, because a count assertion goes green on either being wrong: it
        // survives the payload becoming another message and it survives `1 day` becoming `1 year`, which
        // are precisely the two ways this control silently stops being the control it is named after.
        $this->assertSame(
            [ReconcilePersonReferencesMessage::class . ' @ every 1 day'],
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
