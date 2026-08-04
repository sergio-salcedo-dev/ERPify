<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\ScheduleConsumption\src;

use Symfony\Component\Scheduler\Attribute\AsSchedule;

/**
 * The attribute with no argument at all, which Symfony resolves to the schedule named `default` and whose
 * transport is therefore `scheduler_default`. It is the spelling a developer reaches for first and the one a
 * single-quoted-argument sweep is completely blind to.
 *
 * @internal
 */
#[AsSchedule]
final class DefaultScheduleFixture
{
}
