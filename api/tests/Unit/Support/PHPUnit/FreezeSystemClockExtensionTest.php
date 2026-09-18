<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support\PHPUnit;

use DateTimeInterface;
use Erpify\Shared\Clock\Domain\SystemClock;
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
 * runs, the pin has already happened and what is left to observe is the instant it left behind. That is
 * also why each assertion is against the literal and not against a second read of a clock — comparing
 * `SystemClock::now()` to `new DateTimeImmutable()` is red by microseconds when the pin is gone and green
 * whenever the two reads land in the same one, and a guard that holds by timing is not a guard.
 *
 * **Both sources are asserted, because {@see FreezeSystemClockExtension::pin()} writes two and the second
 * is the one its docblock calls the lane that matters.** Asserting only the ambient half left
 * `SymfonyClockFacade::set()` deletable with the whole suite green: the container's `clock` service would
 * fall back to the wall clock, `SystemClockInitializer` would copy that over the ambient clock at
 * `kernel.request`, and every functional seed this harness fixed reads the SAME service its subject reads —
 * so seed and verdict would agree on the wall clock and no functional test could see it either.
 *
 * **What this cannot witness, stated rather than implied: the leading edge.** `pin()` runs on
 * `PreparationStarted` and again on `Finished`, and the trailing one alone satisfies every assertion here,
 * so deleting the leading subscriber leaves this green. The case the leading edge defends — a previous test
 * that skipped or threw in `setUp()`, and so never reached `Finished`, handing its clock to the next test —
 * cannot be staged from inside a suite that forbids ordering dependencies. It is carried by the argument in
 * the extension's docblock and by nothing else.
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

    #[Test]
    public function theSuitePinsTheGlobalClockTheContainerReadsThrough(): void
    {
        $this->assertSame(
            FreezeSystemClockExtension::SUITE_INSTANT,
            SymfonyClockFacade::get()->now()->format(DateTimeInterface::ATOM),
        );
    }
}
