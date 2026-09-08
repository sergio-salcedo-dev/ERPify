<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support\PHPUnit;

use DateTimeInterface;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Support\PHPUnit\FreezeSystemClockExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The witness that the suite-wide pin is installed — the one thing about {@see FreezeSystemClockExtension}
 * no other test can be relied on to notice.
 *
 * Its subject is ambient state rather than a call, so it credits no coverage: by the time any test body
 * runs, the extension has already fired and what is left to observe is the instant it left behind. That is
 * also why the assertion is against the literal and not against a second read of the clock — comparing
 * `SystemClock::now()` to `new DateTimeImmutable()` is red by microseconds when the pin is gone and green
 * whenever the two reads land in the same one, and a guard that holds by timing is not a guard.
 *
 * Falsified rather than assumed: with the extension pinning, the suite is 3703 green; with its two
 * subscribers put back to `SystemClock::reset()`, this test fails and the ambient clock is the wall clock
 * again.
 *
 * @internal
 */
#[CoversNothing]
final class FreezeSystemClockExtensionTest extends TestCase
{
    #[Test]
    public function theSuitePinsTheAmbientClockForEveryTestThatSetsNoneOfItsOwn(): void
    {
        $this->assertSame(
            FreezeSystemClockExtension::SUITE_INSTANT,
            SystemClock::now()->format(DateTimeInterface::ATOM),
        );
    }
}
