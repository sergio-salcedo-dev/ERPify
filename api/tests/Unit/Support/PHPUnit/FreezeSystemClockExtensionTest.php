<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support\PHPUnit;

use DateTimeInterface;
use Erpify\Tests\Support\PHPUnit\FreezeSystemClockExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock as SymfonyClockFacade;

/**
 * The witness that the suite-wide pin is installed — the one thing about {@see FreezeSystemClockExtension}
 * no other test can be relied on to notice.
 *
 * Its subject is ambient state rather than a call, so it credits no coverage: by the time any test body
 * runs, the pin has already happened and what is left to observe is the instant it left behind. The
 * assertion is against the literal and not against a second read of a clock, because comparing two reads is
 * green whenever they land in the same microsecond, and a guard that holds by timing is not a guard.
 *
 * **What this cannot witness, stated rather than implied: the leading edge.** `pin()` runs on
 * `PreparationStarted` and again on `Finished`, and the trailing one alone satisfies the assertion here, so
 * deleting the leading subscriber leaves this green. The case the leading edge defends — a previous test that
 * skipped or threw in `setUp()` and handed its clock to the next — cannot be staged from inside a suite that
 * forbids ordering dependencies.
 *
 * @internal
 */
#[CoversNothing]
final class FreezeSystemClockExtensionTest extends TestCase
{
    #[Test]
    public function theSuitePinsTheGlobalClockTheContainerReadsThrough(): void
    {
        $this->assertSame(
            FreezeSystemClockExtension::SUITE_INSTANT,
            SymfonyClockFacade::get()->now()->format(DateTimeInterface::ATOM),
        );
    }
}
