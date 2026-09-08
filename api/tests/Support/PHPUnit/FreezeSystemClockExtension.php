<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\PHPUnit;

use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Double\Clock\FixedClock;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Symfony\Component\Clock\Clock as SymfonyClockFacade;
use Symfony\Component\Clock\MockClock;

/**
 * Pins the ambient {@see SystemClock} to one fixed instant around every test, so a frozen instant set in
 * one can never decide the outcome of another sharing the same process — and so no test's verdict can
 * depend on which day the suite runs.
 *
 * **Why it pins rather than clears.** Clearing hands `SystemClock::now()` back to its
 * {@see \Erpify\Shared\Clock\Domain\NativeClock} fallback, which is the host wall clock — and the wall
 * clock IS the failure mode this exists to close. A test that seeds an absolute near-future instant and
 * reads it back through an active-only predicate passes until that date arrives and then fails on a commit
 * that touched nothing; the tree has shipped that bomb at least twice. A pinned instant does not make such
 * a seed correct, it makes it deterministic: whatever verdict it gives, it gives on the first run and every
 * run after, which is the property that keeps a red attributable to the change that caused it.
 *
 * **What it buys, measured rather than estimated: the suite's verdict no longer depends on the instant at
 * all.** 3706 tests are green with `now` pinned at 1999-06-15, at {@see SUITE_INSTANT}, at 2035-01-01 and at
 * 2100-01-01 — a 101-year span over which nothing changes. Reaching that took four fixes and the pin is what
 * made each of them visible, since every one had been green for as long as two clocks happened to agree: a
 * shared functional login seating its session with `new DateTimeImmutable('+1 day')` while the admission gate
 * read the container's clock (**57 tests across 15 classes**, one seed), an aggregate stamp compared against
 * a bare `new DateTimeImmutable()`, a recovery secret minted off the wall clock and redeemed against the
 * container's, and two test-data mothers whose default expiry was a date on the calendar rather than a window
 * from the clock.
 *
 * **The alternative that was measured and rejected**: a clock whose `now()` throws, so a test either
 * freezes time or dies. It closes a strictly wider class — "this test READ the clock" — and costs 720 tests
 * across 192 classes, because {@see \Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot::__construct()}
 * reads the clock, so every aggregate the suite builds touches it. Against a measured calendar-dependence
 * surface of one test, that is 720 rebuilds of unrelated fixtures for the same protection.
 *
 * **Both edges are subscribed, because a trailing pin alone is not a net.** The runner guards that emit on
 * `TestCase::wasPrepared()`, which a test skipping in `setUp()` never sets, and which an unexpected
 * throwable there does not set either — so neither reaches `Finished`. (A failed *assertion* in `setUp()`
 * is the one exception: the runner forces the flag before reporting it, so that branch does emit.)
 *
 * A skip and a throwable in `setUp()` are exactly the cases that leave another test's instant behind: the
 * failure hands the process to the next test with the previous one's clock still installed, and the one
 * guard that could have caught it is the one its own failure skipped. So the pin the following test relies
 * on has to be its own precondition rather than a debt owed by the test before it. `PreparationStarted` is
 * the first per-test event and precedes `setUp()`, which puts the pin ahead of the code most likely to read
 * the clock; `Prepared` fires after that hook and would be too late.
 *
 * The trailing pin stays for the interval the leading one cannot cover — whatever runs between the end of
 * one test and the start of the next, which is where a leaked instant would otherwise sit unattributed.
 *
 * **What the leading pin is deliberately upstream of, and therefore overwrites: the class-level hooks.**
 * `setUpBeforeClass()` / `#[BeforeClass]` run once before the suite's first `PreparationStarted`, and a
 * data provider resolves earlier still, so a clock installed in either is replaced before every test of the
 * class including the first. Under `inIsolation` the ordering inverts, so the behaviour would differ by
 * isolation mode. Freeze the clock per test (`setUp()` or the test body, both of which run after this pin);
 * nothing in the suite freezes it at class level today, and nothing gates that.
 *
 * **Blind spots, because a green here proves less than the pin suggests.** It owns the two time sources
 * {@see pin()} names and no others. A bare `new DateTimeImmutable()` or `time()` reads past both —
 * including `Shared\Event\Domain\DomainEvent`'s `$occurredOn ?? new DateTimeImmutable()` default, a
 * second ambient source in production code, and the handful of test seeds that mint an expiry from the wall
 * clock while the subject reads the container's. Postgres has a clock of its own that nothing here touches.
 * And Behat boots from its own bootstrap, which registers none of this.
 */
final class FreezeSystemClockExtension implements Extension
{
    /**
     * The instant the whole suite reads as "now" unless a test pins its own.
     *
     * Its exact value carries no meaning and none should be read into it; what matters is that it is a
     * literal, so it is the same on every machine and every day. Moving it is measured to change nothing:
     * the suite is green at 1999-06-15 and at 2100-01-01 alike, which is the property that says no test is
     * leaning on this constant rather than on the clock.
     */
    public const string SUITE_INSTANT = '2026-01-01T00:00:00+00:00';

    /**
     * Both halves of the same instant, because the application has two time sources and pinning one of them
     * is how the pin would look installed while the lane that matters most still ran on the wall clock.
     * `SystemClock` is the ambient one aggregates read; Symfony's global clock is what the container's
     * `clock` service delegates to (it is `Clock` itself, constructed with no inner clock), so it is what
     * every injected `Erpify\Shared\Clock\Domain\Clock` reads through `SymfonyClock` — and what
     * `SystemClockInitializer` copies BACK over the ambient one at `kernel.request`, `console.command` and
     * every worker message. Pin only the first and a functional test runs its request on the wall clock,
     * having been handed it by the application's own listener.
     */
    public static function pin(): void
    {
        SystemClock::set(FixedClock::at(self::SUITE_INSTANT));
        SymfonyClockFacade::set(new MockClock(self::SUITE_INSTANT));
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") $configuration and $parameters are mandated by the interface
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            /**
             * @SuppressWarnings("PHPMD.UnusedFormalParameter") $event is mandated by the subscriber interface
             */
            public function notify(PreparationStarted $event): void
            {
                FreezeSystemClockExtension::pin();
            }
        });

        $facade->registerSubscriber(new class implements FinishedSubscriber {
            /**
             * @SuppressWarnings("PHPMD.UnusedFormalParameter") $event is mandated by the subscriber interface
             */
            public function notify(Finished $event): void
            {
                FreezeSystemClockExtension::pin();
            }
        });
    }
}
