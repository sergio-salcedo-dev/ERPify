<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\FailureSignalWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The window admits one signal per name per period, and the three properties that make it worth having.
 *
 * Driven through a clock the test moves by hand rather than by sleeping: the period is 60 seconds, so a
 * real clock would either make the suite take a minute or make the boundary untestable.
 *
 * @internal
 */
#[CoversClass(FailureSignalWindow::class)]
final class FailureSignalWindowTest extends TestCase
{
    public function testTheFirstSignalIsAdmittedAndAnImmediateRepeatIsNot(): void
    {
        $window = new FailureSignalWindow(new MovableClock());

        $this->assertTrue($window->admits('read|read_digest_mismatch'));
        $this->assertFalse($window->admits('read|read_digest_mismatch'));
        $this->assertFalse($window->admits('read|read_digest_mismatch'));
    }

    /**
     * Distinct signals do not suppress one another, which is what keeps the window a rate bound rather than
     * a loss of vocabulary: a deployment failing two ways still reports both.
     */
    public function testDistinctSignalsDoNotSuppressEachOther(): void
    {
        $window = new FailureSignalWindow(new MovableClock());

        $this->assertTrue($window->admits('read|read_digest_mismatch'));
        $this->assertTrue($window->admits('read|read_object_too_large'));
        $this->assertTrue($window->admits('verify_integrity|read_digest_mismatch'));
    }

    /**
     * The boundary in both directions. One second short of the period is still suppressed and the period
     * itself admits, so a `<` drifting to `<=` — or the period being read off the wrong unit — is visible.
     */
    public function testTheSignalIsAdmittedAgainOnceThePeriodHasElapsed(): void
    {
        $clock = new MovableClock();
        $window = new FailureSignalWindow($clock);

        $this->assertTrue($window->admits('read|read_digest_mismatch'));

        $clock->advance(59);
        $this->assertFalse($window->admits('read|read_digest_mismatch'));

        $clock->advance(1);
        $this->assertTrue($window->admits('read|read_digest_mismatch'));
    }

    /**
     * The stamp is taken when the window ADMITS, not after a successful emit, so the period restarts from
     * the admission. Without that, a sink throwing on every call would leave the stamp at the first
     * admission for ever and turn each later request back into an emit attempt — the amplification the
     * window exists to stop, reappearing on the failure path of the thing reporting a failure.
     */
    public function testAdmissionRestartsThePeriodEvenWhenTheCallerGoesOnToFail(): void
    {
        $clock = new MovableClock();
        $window = new FailureSignalWindow($clock);

        $this->assertTrue($window->admits('read|read_digest_mismatch'));

        $clock->advance(60);
        $this->assertTrue($window->admits('read|read_digest_mismatch'));

        $clock->advance(59);
        $this->assertFalse($window->admits('read|read_digest_mismatch'));
    }
}
