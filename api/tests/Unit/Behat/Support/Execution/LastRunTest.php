<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Execution;

use Erpify\Tests\Behat\Support\Execution\LastRun;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the two properties that decide whether the run assertions can fail at all.
 *
 * Both failure modes are silent by construction. An unwritten holder answering 0 would satisfy
 * "the last run should succeed" with nothing having executed, and a holder that survived a scenario
 * would let the next one assert on the previous scenario's exit code — in both cases a green step
 * over a run that never happened, which no acceptance scenario can distinguish from the real thing.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the
 * coverage allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class LastRunTest extends TestCase
{
    public function testItReportsWhatWasRecorded(): void
    {
        $lastRun = new LastRun();
        $lastRun->record(3, 'the output');

        $this->assertSame(3, $lastRun->exitCode());
        $this->assertSame('the output', $lastRun->output());
    }

    public function testReadingAnExitCodeBeforeAnythingRanRefusesInsteadOfAnsweringSuccess(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Nothing has been run yet in this scenario');

        (new LastRun())->exitCode();
    }

    public function testReadingOutputBeforeAnythingRanRefusesInsteadOfAnsweringEmpty(): void
    {
        // An empty string would quietly satisfy "should not contain", which is the direction that
        // stays green: a scenario asserting an absence would prove it against a run it never made.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Nothing has been run yet in this scenario');

        (new LastRun())->output();
    }

    public function testResetPutsItBackToHavingRunNothing(): void
    {
        $lastRun = new LastRun();
        $lastRun->record(0, 'from the previous scenario');
        $lastRun->reset();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Nothing has been run yet in this scenario');

        $lastRun->exitCode();
    }
}
