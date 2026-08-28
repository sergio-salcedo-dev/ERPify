<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Execution;

use RuntimeException;

/**
 * What the last thing a scenario ran left behind: an exit code and the output it produced.
 *
 * It exists so the suite has one vocabulary for "did that work" instead of one per mechanism. Two
 * contexts run things — {@see \Erpify\Tests\Behat\Context\SymfonyCommandContext} drives a console
 * {@see \Symfony\Component\Console\Application}, {@see \Erpify\Tests\Behat\Context\MessengerConsumerContext}
 * drives a Messenger {@see \Symfony\Component\Messenger\Worker} — and each had grown its own phrases
 * for the same three assertions. Behat resolves a step by pattern, so two contexts cannot both
 * register one phrase; the way to share the words is to share the *result*, which is this.
 *
 * Deliberately not "the last command": the worker is not a command. Its exit code is synthesised
 * from whether the run threw, and its output is a logger buffer, so a phrase naming a command would
 * be false half the time it is read — and a step whose subject is wrong is how a reader comes to
 * trust the wrong thing.
 *
 * Reading before anything ran is a broken scenario, not an empty result, so it refuses rather than
 * answering 0 — an exit code of 0 for "nothing ran" would satisfy "should succeed" without a single
 * line of code having executed.
 *
 * One holder means "the last run" is literal: only the most recent invocation is assertable. That
 * makes a second run inside one scenario a DESTRUCTIVE act — it discards a result nobody looked at,
 * and every step downstream then asserts against the wrong invocation while reading as though it
 * could not. So the holder refuses it. "Was it read" is the discriminator rather than "was there a
 * previous run", because overwriting a result a step already asserted on is the ordinary case the
 * whole vocabulary is built for; overwriting one nothing asked about is the scenario losing an
 * assertion silently. {@see reset()} is not that act: it ends the scenario's turn rather than
 * replacing a result inside it, so it clears the obligation instead of tripping over it.
 */
final class LastRun
{
    private const string NOTHING_RAN = 'Nothing has been run yet in this scenario';

    private const string UNREAD_RUN = 'The previous run in this scenario was never asserted on before this one '
        . 'started, so its exit code and output are about to be discarded unread. Assert each run '
        . 'before starting the next.';

    private ?int $exitCode = null;

    private string $output = '';

    private bool $read = false;

    /**
     * @throws RuntimeException when a previous run in this scenario was never read
     */
    public function record(int $exitCode, string $output): void
    {
        if (null !== $this->exitCode && !$this->read) {
            throw new RuntimeException(self::UNREAD_RUN);
        }

        $this->exitCode = $exitCode;
        $this->output = $output;
        $this->read = false;
    }

    public function reset(): void
    {
        $this->exitCode = null;
        $this->output = '';
        $this->read = false;
    }

    public function exitCode(): int
    {
        $exitCode = $this->exitCode ?? throw new RuntimeException(self::NOTHING_RAN);

        $this->read = true;

        return $exitCode;
    }

    public function output(): string
    {
        if (null === $this->exitCode) {
            throw new RuntimeException(self::NOTHING_RAN);
        }

        $this->read = true;

        return $this->output;
    }
}
