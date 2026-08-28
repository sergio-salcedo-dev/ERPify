<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Execution\LastRun;
use JsonException;
use Symfony\Component\Console\Command\Command;

/**
 * The one vocabulary for asserting on something the scenario ran, whatever ran it.
 *
 * The contexts that *invoke* keep their own words, because what they start really is different — a
 * console command is not a Messenger consume. What follows is not: "it worked", "it did not" and
 * "the output said" are the same three claims either way, and one phrasing for each is what keeps a
 * reader from having to know which mechanism is behind a scenario before picking a step.
 *
 * The subject is named "run" rather than "command" on purpose: {@see MessengerConsumerContext} runs
 * a {@see \Symfony\Component\Messenger\Worker} directly, so calling it a command would be false for
 * every messenger scenario that reads these steps.
 *
 * One vocabulary over one holder means "the last run" is literal: within a scenario only the most
 * recent invocation is assertable, whichever context made it. A scenario that consumes and then runs a
 * console command has no way to assert on the consume — and the step still reads as though it could.
 * Assert each run before starting the next.
 *
 * @see LastRun for why the result is shared rather than the step definitions
 */
final class RunOutcomeContext extends AbstractContext
{
    public function __construct(private readonly LastRun $lastRun)
    {
    }

    /**
     * The holder outlives a scenario, so without this a scenario asserting on a run it never made
     * would read the previous scenario's exit code and pass on it.
     */
    #[BeforeScenario]
    public function reset(): void
    {
        $this->lastRun->reset();
    }

    #[Then('the last run should succeed')]
    public function theLastRunShouldSucceed(): void
    {
        $exitCode = $this->lastRun->exitCode();

        self::assertSame(
            Command::SUCCESS,
            $exitCode,
            \sprintf('The last run failed (code %d). Output:%s%s', $exitCode, PHP_EOL, $this->lastRun->output()),
        );
    }

    /**
     * "Failed" is the exit code being non-zero. For a console command that is what the command
     * returned; for a Messenger worker it means the run threw, since a worker has no exit code of
     * its own and {@see MessengerConsumerContext} synthesises one from that.
     */
    #[Then('the last run should fail')]
    public function theLastRunShouldFail(): void
    {
        self::assertNotSame(
            Command::SUCCESS,
            $this->lastRun->exitCode(),
            \sprintf(
                'The last run unexpectedly succeeded. Output:%s%s',
                PHP_EOL,
                $this->lastRun->output(),
            ),
        );
    }

    #[Then('the last run output should contain :text')]
    public function theLastRunOutputShouldContain(string $text): void
    {
        $output = $this->lastRun->output();

        self::assertStringContainsString(
            $text,
            $output,
            \sprintf('The last run output did not contain "%s". Output:%s%s', $text, PHP_EOL, $output),
        );
    }

    /**
     * The counterpart of {@see theLastRunOutputShouldContain()}, for the claims a run makes by staying
     * silent. What a report deliberately withholds is as much its contract as what it prints — an
     * identity id kept out of an operator's terminal is a decision, and without an assertion it is
     * only an intention.
     *
     * An absence is only a claim about the run when the run said something. Over an empty buffer the
     * assertion satisfies itself, and it does so for whatever text is asked of it, so a green means
     * "nothing was printed" and reads as "that value was withheld" — the one failure a step written to
     * prove a withholding may not have. It is reachable rather than theoretical: a run producing no
     * output at all is the normal state of a {@see MessengerConsumerContext} consume, whose whole
     * output is a {@see \Symfony\Component\Console\Logger\ConsoleLogger} buffer that logs at info
     * and debug — nothing at all at normal verbosity. So the step refuses the empty buffer instead of
     * passing over it, and a scenario wanting to say "this run printed nothing" says that with a
     * different assertion rather than borrowing this one's silence.
     *
     * Whitespace counts as nothing: a lone newline is a report as absent as no bytes at all, and
     * admitting it would leave the same green available one character away.
     */
    #[Then('the last run output should not contain :text')]
    public function theLastRunOutputShouldNotContain(string $text): void
    {
        $output = $this->lastRun->output();

        self::assertNotSame(
            '',
            \trim($output),
            \sprintf(
                'The last run produced no output, so the absence of "%s" is not observable in it.',
                $text,
            ),
        );

        self::assertStringNotContainsString(
            $text,
            $output,
            \sprintf('The last run output unexpectedly contained "%s". Output:%s%s', $text, PHP_EOL, $output),
        );
    }

    /**
     * @throws JsonException when the output is not valid JSON
     */
    #[Then('the last run output should be JSON with a :field field')]
    public function theLastRunOutputShouldBeJsonWithField(string $field): void
    {
        $output = $this->lastRun->output();

        /** @var array<string, mixed> $decoded */
        $decoded = (array) \json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey(
            $field,
            $decoded,
            \sprintf('The last run output was not JSON with a "%s" field. Output:%s%s', $field, PHP_EOL, $output),
        );
    }
}
