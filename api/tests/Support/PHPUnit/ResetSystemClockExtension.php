<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\PHPUnit;

use Erpify\Shared\Clock\Domain\SystemClock;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Resets the ambient {@see SystemClock} around every test, so a frozen instant set in one
 * (`SystemClock::set(new FixedClock(...))`) can never decide the outcome of another sharing the same process.
 * The per-test `tearDown()` reset that freezing tests already do becomes a belt-and-braces; this extension is
 * the suite-wide net that does not depend on every future test author remembering it.
 *
 * **Both edges are subscribed, because a trailing reset alone is not a net.** The runner guards that emit on
 * `TestCase::wasPrepared()`, which a test skipping in `setUp()` never sets, and which an unexpected throwable
 * there does not set either — so neither reaches `Finished`. (A failed *assertion* in `setUp()` is the one
 * exception: the runner forces the flag before reporting it, so that branch does emit.)
 *
 * A skip and a throwable in `setUp()` are exactly the cases that leave a frozen clock behind: the failure
 * hands the process to the next test with the previous one's instant still installed, and the one guard that
 * could have caught it is the one its own failure skipped. So the reset the following test relies on has to
 * be its own precondition rather than a debt owed by the test before it. `PreparationStarted` is the first
 * per-test event and precedes `setUp()`, which puts the reset ahead of the code most likely to read the
 * clock; `Prepared` fires after that hook and would be too late.
 *
 * The trailing reset stays for the interval the leading one cannot cover — whatever runs between the end of
 * one test and the start of the next, which is where a leaked instant would otherwise sit unattributed.
 */
final class ResetSystemClockExtension implements Extension
{
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
                SystemClock::reset();
            }
        });

        $facade->registerSubscriber(new class implements FinishedSubscriber {
            /**
             * @SuppressWarnings("PHPMD.UnusedFormalParameter") $event is mandated by the subscriber interface
             */
            public function notify(Finished $event): void
            {
                SystemClock::reset();
            }
        });
    }
}
