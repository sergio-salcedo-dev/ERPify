<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\ScheduleConsumption\src;

/**
 * The attribute written fully qualified instead of imported. The style fixers shorten an already-imported
 * class and leave this alone, so it is a spelling that can survive review.
 *
 * @internal
 */
#[\Symfony\Component\Scheduler\Attribute\AsSchedule('fqcn')]
final class FullyQualifiedScheduleFixture
{
}
