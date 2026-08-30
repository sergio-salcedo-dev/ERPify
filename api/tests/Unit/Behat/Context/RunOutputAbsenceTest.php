<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Behat\Context;

use Erpify\Tests\Behat\Context\RunOutcomeContext;
use Erpify\Tests\Behat\Support\Execution\LastRun;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the one property of `the last run output should not contain :text` that no scenario above it
 * can observe: it must not prove an absence against a run that printed nothing.
 *
 * A negative string assertion is satisfied by the empty string, and satisfied by it for EVERY text —
 * so over an empty buffer the step is green whatever it is asked, and its green means "the run said
 * nothing" while reading as "the run withheld that value". Gherkin cannot tell those apart: it can
 * assert that a step passes, never that the step could have failed. That is the whole reason the
 * guard has to live here rather than in a feature.
 *
 * It is a reachable state and not a hypothetical. A {@see \Erpify\Tests\Behat\Context\MessengerConsumerContext}
 * consume logs through a {@see \Symfony\Component\Console\Logger\ConsoleLogger} at info and debug, so
 * at normal verbosity its whole output is the empty string, and the step would certify a withholding
 * over a run whose report nobody wrote.
 *
 * The passing cases are not decoration. Without them a step that refused every input would satisfy
 * the empty-buffer tests just as well, and the file would prove nothing.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the
 * coverage allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class RunOutputAbsenceTest extends TestCase
{
    private const string REFUSAL = 'The last run produced no output';

    public function testAnAbsenceIsNotProvenOverARunThatPrintedNothing(): void
    {
        $context = $this->contextOverOutput('');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains(self::REFUSAL);

        $context->theLastRunOutputShouldNotContain('MANAGER');
    }

    /**
     * A lone newline is a report as absent as no bytes at all, so admitting it would leave the same
     * unfalsifiable green one character away from the case above.
     */
    public function testAnAbsenceIsNotProvenOverAnOutputOfWhitespaceAlone(): void
    {
        $context = $this->contextOverOutput("\n   \n\t");

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains(self::REFUSAL);

        $context->theLastRunOutputShouldNotContain('MANAGER');
    }

    /**
     * The half that keeps the guard from being a refusal of everything: a run that printed a report
     * genuinely not naming the text still passes, which is the assertion the six live usages make.
     */
    public function testAnAbsenceInARunThatPrintedSomethingElseStillPasses(): void
    {
        $context = $this->contextOverOutput('Roles the enum no longer knows: GHOST_ROLE');

        // No assertion is added here on purpose. The step performs its own two — the non-empty guard
        // and the absence itself — so the count is real, and a guard that refused everything would
        // throw. Adding assertTrue(true) would be a claim nothing can falsify, which is the defect
        // this very file exists to close.
        $context->theLastRunOutputShouldNotContain('MANAGER');
    }

    /**
     * The other direction the guard must not have broken: a text the run DID print is still refused,
     * and refused for containing it rather than for the buffer being empty.
     */
    public function testATextTheRunPrintedIsStillRefused(): void
    {
        $context = $this->contextOverOutput('Roles the enum no longer knows: MANAGER, GHOST_ROLE');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('unexpectedly contained "MANAGER"');

        $context->theLastRunOutputShouldNotContain('MANAGER');
    }

    /**
     * One holder per case, recorded once: the step under test is the subject here, not the holder's
     * own refusal to be overwritten unread.
     */
    private function contextOverOutput(string $output): RunOutcomeContext
    {
        $lastRun = new LastRun();
        $lastRun->record(0, $output);

        return new RunOutcomeContext($lastRun);
    }
}
