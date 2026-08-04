<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\ScheduleConsumption\src;

use Symfony\Component\Scheduler\Attribute\AsSchedule;

/**
 * A schedule declaration standing in for the real ones so the sweep can be proven to SEE an attribute rather
 * than assumed to. It lives under its own source root, which the rules gate points the sweep at: asserting
 * against `api/src` would only ever confirm the names that happen to be there today.
 *
 * @internal
 */
#[AsSchedule('alpha')]
final class AlphaScheduleFixture
{
}
