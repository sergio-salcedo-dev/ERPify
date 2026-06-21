<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\PHPUnit;

use Erpify\Shared\Clock\Domain\SystemClock;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Resets the ambient {@see SystemClock} after every test so a frozen instant set in one test
 * (`SystemClock::set(new SymfonyClock(new MockClock(...)))`) can never leak into the next one
 * sharing the same process. The per-test `tearDown()` reset that freezing tests already do becomes
 * a belt-and-braces; this extension is the suite-wide net that does not depend on every future test
 * author remembering it.
 */
final class ResetSystemClockExtension implements Extension
{
    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") $configuration and $parameters are mandated by the interface
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
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
