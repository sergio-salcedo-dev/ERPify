<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\PHPUnit;

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
 * Pins Symfony's global clock to one fixed instant around every test, so a frozen instant set in one can never
 * decide the outcome of another sharing the same process — and so no test's verdict can depend on which day
 * the suite runs.
 *
 * **There is one time source left to pin.** Aggregates and domain events receive their instant from the
 * application layer and read no clock of their own, so the only clock the application still reads is the
 * injected `Erpify\Shared\Clock\Domain\Clock` — the `SymfonyClock` adapter over the container's `clock`
 * service, which is Symfony's global clock constructed with no inner clock. A unit test that injects a
 * {@see \Erpify\Tests\Double\Clock\FixedClock} never reaches this pin; a functional test that drives a
 * request through the container does, and without it would run on the wall clock.
 *
 * **Why it pins rather than clears.** Clearing hands the global clock back to the host wall clock, which IS
 * the failure mode this exists to close. A test that seeds an absolute near-future instant and reads it back
 * through an active-only predicate passes until that date arrives and then fails on a commit that touched
 * nothing; the tree has shipped that bomb at least twice. A pinned instant does not make such a seed correct,
 * it makes it deterministic: whatever verdict it gives, it gives on the first run and every run after.
 *
 * **Both edges are subscribed, because a trailing pin alone is not a net.** The runner guards that emit on
 * `TestCase::wasPrepared()`, which a test skipping in `setUp()` never sets, and which an unexpected
 * throwable there does not set either — so neither reaches `Finished`, and the next test would inherit the
 * previous one's clock. `PreparationStarted` is the first per-test event and precedes `setUp()`; the trailing
 * pin covers whatever runs between one test's end and the next one's start.
 *
 * **Windows outside every per-test event are covered from `api/tools/phpunit/bootstrap.php`**, which calls
 * {@see pin()} too: a data provider resolving while the suite is BUILT, `setUpBeforeClass()`, and an isolated
 * child process, whose template registers no extension at all but does `require_once` the bootstrap.
 *
 * **Blind spots.** A bare `new DateTimeImmutable()` or `time()` reads past the pin, Postgres keeps a clock of
 * its own, and Behat boots from its own bootstrap, which registers none of this.
 */
final class FreezeSystemClockExtension implements Extension
{
    /**
     * The instant the whole suite reads as "now" unless a test pins its own.
     *
     * No test may lean on it — that is measured, and the sweep above is the measurement. But the value is
     * not therefore arbitrary, and saying it was is what put this constant on `2026-01-01T00:00:00+00:00`,
     * **the most-used date literal in the whole test tree**: 66 occurrences, against 27 for the next, with
     * 368 of the tree's 381 date literals in that same year. Two costs followed, and neither is aesthetic.
     * A failure diff showing that string sent the reader to 65 files instead of to this one; and, worse, an
     * assertion like `assertSame('2026-01-01T00:00:00+00:00', $x->createdAt)` became true by two
     * independent paths — the code copied what the test seeded, or the code read the clock — so it stopped
     * falsifying the mapping it was written for.
     *
     * Two properties, then, and both are cheap to keep:
     *
     *   - **A year the tree does not use**, so the value in a failure diff can only have come from here.
     *     `2099` and `2100` are taken: the tree already spells them 19 and 7 times as its idioms for "far
     *     future" and "locked for ever".
     *   - **Away from every boundary.** `2026-01-01T00:00:00` sat on the day, month and year boundary at
     *     once, so a test doing `->modify('-1 second')` crossed all three. Mid-month and mid-day leaves
     *     ±14 days and ±11 hours of room.
     *
     * Within those two, the value is a preference and nothing more.
     */
    public const string SUITE_INSTANT = '2050-06-15T12:00:00+00:00';

    /**
     * Symfony's global clock is what the container's `clock` service delegates to, so it is what every
     * injected `Erpify\Shared\Clock\Domain\Clock` reads through `SymfonyClock`, and what a fixture reads
     * through `Erpify\Tests\DataFixtures\SeedInstant`. {@see \Erpify\Tests\Double\Clock\SuiteInstant}
     * hands the same instant to an aggregate a test builds directly, so the two agree without either reading
     * the other.
     */
    public static function pin(): void
    {
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
