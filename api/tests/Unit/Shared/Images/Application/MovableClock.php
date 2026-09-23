<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Tests\Double\Clock\SuiteInstant;
use Override;

/**
 * A clock the test moves, so the 60-second period can be crossed without the suite waiting for it.
 *
 * It starts at the suite instant, because the only thing this double owes a test is a distance:
 * every consumer moves it with {@see advance()} and asserts about the window, never about the date. An
 * absolute starting literal would state a day that nothing depends on, which is the shape
 * `docs/rules/testing.md` asks the rest of the tree to stop writing.
 *
 * Nothing can go red on this: no assertion reads the starting instant, so replanting a literal here would
 * pass. It is a readability fix and not a guarded invariant, and saying so is cheaper than a test that
 * guards a property no consumer has.
 */
final class MovableClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = SuiteInstant::now();
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(\sprintf('+%d seconds', $seconds));
    }
}
