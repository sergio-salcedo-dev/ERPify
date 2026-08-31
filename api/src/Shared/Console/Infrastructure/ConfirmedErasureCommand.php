<?php

declare(strict_types=1);

namespace Erpify\Shared\Console\Infrastructure;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The four modes a run of an irreversible erasure can be in, and the order that decides between them when
 * more than one applies: the no-op the operator asked for, the run that says up front it needs no answer,
 * the run that cannot be asked, and the question itself.
 *
 * **Why the question lives here and not at each call site.** {@see UnattendedRunPolicy} centralises what
 * does not vary and leaves what does at the call site, and the deciding question is which of the two the
 * PLACEMENT is. Erasing one subject does not vary it: every such command asks the same sentence in the same
 * position, and a copy is where the next ordering defect comes from. A command that inherits this sequence
 * cannot get the order wrong; one that copies it can.
 *
 * **Why the audit-trail erasure does NOT extend this, and that exclusion is the same principle.** Its
 * order genuinely differs: it reads a row count between refusing an unanswerable run and putting the
 * question, so its preview carries a magnitude and its confirmation is gated on there being anything left
 * to erase. Expressing that as a hook would move a real ordering decision behind a contract between parent
 * and child — and ordering is precisely where every defect this protocol exists to prevent has lived. It
 * keeps its own pre-flight, in the open, and the confirmation gate holds it to the same two guards.
 *
 * A subclass supplies the sentence it asks and nothing else; the exit code each outcome earns is fixed
 * here, because that code is the contract with an unattended caller reading `$?` rather than the screen.
 *
 * @internal
 */
abstract class ConfirmedErasureCommand extends Command
{
    /**
     * The sentence the operator is asked before anything irreversible happens. Phrased so that what will be
     * destroyed is legible without leaving the prompt.
     */
    abstract protected function confirmationQuestion(): string;

    /**
     * The mode table. It is a table rather than a sequence of guards because the precedence is the
     * contract: a run that asked for a preview gets one even when it also passed `--force`, and a run that
     * cannot be asked is turned away before anything reads the subject.
     *
     * The outcomes are not one outcome: a dry run is the no-op the operator asked for; a confirmation
     * answered "no" is a rejection the operator expressed; and a run that could never put the question is a
     * rejection nobody expressed, which is the only one that must not report success.
     *
     * @return int|null the exit code to stop on, or null to proceed with the erasure
     */
    final protected function preflight(SymfonyStyle $io, InputInterface $input): ?int
    {
        return match (true) {
            true === $input->getOption('dry-run') => $this->reportDryRun($io),
            true === $input->getOption('force') => null,
            UnattendedRunPolicy::cannotAnswer($input) => $this->refuseUnaskableRun($io),
            default => $this->confirmErasure($io, $input),
        };
    }

    private function reportDryRun(SymfonyStyle $io): int
    {
        $io->note('Dry run: nothing was erased.');

        return Command::SUCCESS;
    }

    /** One sentence for both shapes of unanswered question, so neither can drift away from the other. */
    private function refuseUnaskableRun(SymfonyStyle $io): int
    {
        return UnattendedRunPolicy::refuse($io, 'erase', 'the target', 'Nothing was erased.');
    }

    /**
     * The question, and the second shape of unanswered question — the one only a re-read can see. A stdin
     * nothing can be read from enters the question interactive and leaves it demoted: the helper answers
     * with the default it was handed rather than raising, so reading the flag again immediately after is
     * what separates a typed "no" from a question nobody was there to hear.
     * {@see UnattendedRunPolicy::cannotAnswer()} covers the other two shapes and says why it cannot cover
     * this one, which is why nothing may come between the question and the re-read.
     *
     * @return int|null the exit code to stop on, or null to proceed with the erasure
     */
    private function confirmErasure(SymfonyStyle $io, InputInterface $input): ?int
    {
        $confirmed = $io->confirm($this->confirmationQuestion(), false);

        if (!$input->isInteractive()) {
            return $this->refuseUnaskableRun($io);
        }

        if (!$confirmed) {
            $io->warning('Aborted — nothing was erased.');

            return Command::SUCCESS;
        }

        return null;
    }
}
