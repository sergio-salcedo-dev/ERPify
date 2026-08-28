<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Support\Execution;

use Erpify\Tests\Behat\Support\Execution\LastRun;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the properties that decide whether the run assertions can fail at all.
 *
 * Every failure mode here is silent by construction. An unwritten holder answering 0 would satisfy
 * "the last run should succeed" with nothing having executed; a holder that survived a scenario
 * would let the next one assert on the previous scenario's exit code; and a second run inside one
 * scenario would replace a result nobody looked at, so every step after it asserts against the wrong
 * invocation. All three are a green step over a run that never happened or was never the subject,
 * which no acceptance scenario can distinguish from the real thing — Gherkin can assert that a step
 * passes, never that the result it read was the one the scenario meant.
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
        $this->expectExceptionMessageIs('Nothing has been run yet in this scenario');

        (new LastRun())->exitCode();
    }

    public function testReadingOutputBeforeAnythingRanRefusesInsteadOfAnsweringEmpty(): void
    {
        // An empty string would quietly satisfy "should not contain", which is the direction that
        // stays green: a scenario asserting an absence would prove it against a run it never made.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Nothing has been run yet in this scenario');

        (new LastRun())->output();
    }

    public function testResetPutsItBackToHavingRunNothing(): void
    {
        $lastRun = new LastRun();
        $lastRun->record(0, 'from the previous scenario');
        $lastRun->reset();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Nothing has been run yet in this scenario');

        $lastRun->exitCode();
    }

    /**
     * The discarding half. A scenario that runs twice keeps only the second result, so every
     * assertion after it is about an invocation the scenario never named — and the first run's exit
     * code and output are gone with no signal at all. The rule was written in
     * {@see \Erpify\Tests\Behat\Context\RunOutcomeContext}'s docblock and enforced by nothing.
     */
    public function testRecordingOverARunNothingReadIsRefused(): void
    {
        $lastRun = new LastRun();
        $lastRun->record(0, 'the result nobody asserted on');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Assert each run before starting the next.');

        $lastRun->record(1, 'the run that would have discarded it');
    }

    /**
     * Not decoration: a holder that refused every second run would satisfy the case above just as
     * well, and would break the scenarios that legitimately run twice — asserting in between, which
     * is exactly what the rule asks for. Reading either half is enough to discharge the obligation,
     * so this pins the output side and leaves the exit-code side to the case below.
     */
    public function testRecordingOverARunThatWasReadIsAccepted(): void
    {
        $lastRun = new LastRun();
        $lastRun->record(0, 'the first result');
        $lastRun->output();

        $lastRun->record(7, 'the second result');

        $this->assertSame(7, $lastRun->exitCode());
        $this->assertSame('the second result', $lastRun->output());
    }

    /**
     * The other reader discharges it too. See {@see testRecordingOverARunThatWasReadIsAccepted()}.
     */
    public function testReadingTheExitCodeAloneDischargesTheObligation(): void
    {
        $lastRun = new LastRun();
        $lastRun->record(0, 'the first result');
        $lastRun->exitCode();

        $lastRun->record(2, 'the second result');

        $this->assertSame(2, $lastRun->exitCode());
    }

    /**
     * A reset ends the scenario's turn rather than replacing a result inside it, so it discharges the
     * obligation instead of tripping over it. Without this, a scenario that runs something and asserts
     * only its side effects — legal Gherkin, and the shape a consume-then-check-the-database scenario
     * naturally takes — would leave the obligation standing, and the guard would fire in the NEXT
     * scenario's `BeforeScenario` hook: a failure attributed to the wrong scenario, over a rule that
     * scenario did not break. No feature ends on an unread run today (measured over `api/features`),
     * which is exactly why nothing else would catch this direction going wrong.
     */
    public function testAResetClearsTheObligationSoTheNextScenarioCanRecord(): void
    {
        $lastRun = new LastRun();
        $lastRun->record(0, 'from the previous scenario, never read');
        $lastRun->reset();

        $lastRun->record(5, 'from this scenario');

        $this->assertSame(5, $lastRun->exitCode());
    }
}
