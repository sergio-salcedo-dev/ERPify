<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\ScheduleConsumption\src;

use Symfony\Component\Scheduler\Attribute\AsSchedule;

/**
 * The name passed as a named argument — identical to Symfony, invisible to a positional-only pattern.
 *
 * @internal
 */
#[AsSchedule(name: 'named')]
final class NamedArgumentScheduleFixture
{
}
