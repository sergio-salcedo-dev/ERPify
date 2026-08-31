<?php

declare(strict_types=1);

namespace Erpify\Shared\Console\Infrastructure;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What makes a console run unable to answer its own confirmation, and what it is told when it cannot.
 *
 * **The policy is shared; the placement is shared only where it does not vary, and that distinction is the
 * whole design.** A command that asks before doing something irreversible has to decide where in its own
 * flow the question belongs, and every defect this class exists to prevent has been a defect of ordering
 * rather than of the test itself. So nothing here calls anything: this class is the predicate and the
 * sentence, and it is reached from wherever the caller decides.
 *
 * Where the order genuinely varies it stays in the open: the audit trail erasure counts rows and returns
 * early on zero before it ever asks, so it holds its own pre-flight. Where it does not vary it is inherited
 * — {@see ConfirmedErasureCommand} holds the subject-erasure sequence once, so a command taking that route
 * cannot copy the order wrong.
 *
 * @internal
 */
final class UnattendedRunPolicy
{
    /**
     * Whether this run can put a question at all. Three mechanisms, and only the first is the flag:
     *
     * 1. `--no-interaction` (and `--quiet`, `--silent`, or a negative `SHELL_VERBOSITY` inherited from a
     *    parent process, all of which `Application::configureIO()` folds into the same flag) says so up front.
     * 2. A stdin that yields nothing demotes the input *during* the question rather than before it: the
     *    helper catches its own `MissingInputException`, calls `setInteractive(false)` and answers with the
     *    default it was handed. Only a re-read **after** `confirm()` sees that, which is why this predicate
     *    cannot replace it and every caller still has to re-read.
     * 3. A stream a previous read already exhausted never even reaches that path. `QuestionHelper::
     *    doReadInput()` loops `while (!feof($inputStream))`, so a stream arriving already at EOF never enters
     *    the loop, returns `''` rather than `false`, and never raises. The reachable producer is the console's
     *    own single-alternative prompt: a mistyped command name with exactly one near match makes
     *    `Application::doRun()` ask a confirmation of its own, and a pipe whose last byte is not a newline is
     *    exhausted by it.
     *
     * This covers 1 and 3. `feof()` is true only after a read has hit the end, so it refuses the drained
     * stream and leaves an unread empty pipe — which is answerable-looking until the helper tries — to the
     * post-`confirm()` re-read that owns case 2.
     *
     * The stream is resolved exactly as `QuestionHelper::ask()` resolves it, the input's own falling back to
     * `STDIN`, because a guard reading a different stream from the one that will be asked is not a guard.
     */
    public static function cannotAnswer(InputInterface $input): bool
    {
        if (!$input->isInteractive()) {
            return true;
        }

        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= STDIN;

        return \is_resource($stream) && \feof($stream);
    }

    /**
     * The refusal, and it answers `INVALID` rather than `FAILURE` on purpose: nothing was attempted and the
     * command line is what needs repairing, so this is the one code a caller must not retry on.
     *
     * `--quiet`, `--silent` and a negative `SHELL_VERBOSITY` suppress this message while still refusing, so
     * the exit code is the whole of what such a run learns.
     *
     * @param string $verb        what the command would have done, as an infinitive ("erase", "anonymise")
     * @param string $dryRunHint  what `--dry-run` reports instead, as a noun phrase
     * @param string $reassurance what the caller may rely on not having happened
     */
    public static function refuse(SymfonyStyle $io, string $verb, string $dryRunHint, string $reassurance): int
    {
        $io->error(\sprintf(
            'Refusing to %s: this run cannot ask for a confirmation (--no-interaction, a closed or '
            . 'already-exhausted stdin, or a suppressed verbosity) and no confirmation was given. Pass '
            . '--force to %s unattended, or --dry-run to report %s without touching it. %s',
            $verb,
            $verb,
            $dryRunHint,
            $reassurance,
        ));

        return Command::INVALID;
    }
}
