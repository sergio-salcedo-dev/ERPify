<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\EraseActorAuditTrail;
use Erpify\Shared\Console\Infrastructure\UnattendedRunPolicy;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * GDPR "right to erasure" entry point: anonymises one subject's audit trail when an operator handles a
 * data-subject request. A console command rather than an HTTP route because there is no authentication
 * yet — an unauthenticated erasure endpoint would be a footgun, so the trigger stays operator-only until
 * the identity context can protect it. Synchronous: the scope is one subject's rows, not a table sweep.
 *
 * The erasure is itself recorded as a `security` audit entry carrying the resulting pseudonym (never the
 * original id), so the act of forgetting is provable for compliance without re-identifying the person. The
 * rewrite and that entry are one transaction ({@see EraseActorAuditTrail}): either both exist or neither does.
 *
 * **Exit codes are this command's contract with an unattended caller**, which reads `$?` and never the screen.
 * `SUCCESS` means the trail is anonymised, or that a no-op happened on a run that could answer — no matching
 * rows, `--dry-run`, or a confirmation answered "no"; a run that could not put its confirmation answers `INVALID`
 * whatever the trail holds. `FAILURE` means the erasure did not complete: either the count failed, so nothing was
 * attempted, or the transaction failed and rolled back, so the rows keep their original id and the run is safe to
 * repeat. The one ambiguity left is a connection lost at the `COMMIT` itself, whose outcome nobody can know from
 * here — which is why the message says to verify with `--dry-run` (zero rows for the original id means it
 * committed) rather than to repeat blindly. `INVALID` means nothing was attempted and the command line is what
 * needs repairing — a malformed id, or a confirmation this run could not put — and it is the one code a caller
 * must not retry on.
 *
 * Three spellings suppress the refusal's own message while still refusing: `--quiet`, `--silent`, and a
 * negative `SHELL_VERBOSITY` inherited from a parent process. An unattended run that means to erase passes
 * `--force`. What no exit code here can cover is a command line the console cannot **bind** — an unknown
 * option, a wrong arity, a mistyped name — which raises before `execute()` and exits `1`, so "never retry on
 * `INVALID`" is a floor on retries rather than a partition of them.
 */
#[AsCommand(
    name: 'audit:gdpr:erase',
    description: "Irreversibly anonymise one actor's audit trail (GDPR right to erasure)",
)]
final class EraseActorAuditTrailCommand extends Command
{
    public function __construct(
        private readonly AuditActorAnonymiser $anonymiser,
        private readonly EraseActorAuditTrail $eraseActorAuditTrail,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('actor-id', InputArgument::REQUIRED, 'The actor id (UUID) whose audit trail to erase')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the matching row count without mutating')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command anonymises every <comment>audit_log</comment> row of
                one actor: <comment>actor_id</comment> is overwritten with a single fresh random UUID and
                <comment>ip</comment>/<comment>user_agent</comment> are redacted. Rows are never deleted —
                the security trail survives, it just stops being attributable to the person.

                <comment>This is the ACTOR axis only, and it is not a complete GDPR erasure for a person.</comment>
                Rows that <options=bold>name</> the subject in <comment>resource_id</comment> are untouched
                here, so running this alone on someone who also appears as a resource leaves their real id
                beside the fresh pseudonym. Use <info>identity:gdpr:erase-subject</info> for an identity —
                it erases both axes in one transaction.

                Exit codes: <comment>0</comment> anonymised, or the no-op you asked for on a run that
                could answer (including an actor with no matching rows); <comment>1</comment> the erasure
                did not complete — the rows keep their original id and the run is safe to repeat, unless
                the connection was lost at the commit itself, which <comment>--dry-run</comment> settles
                (zero rows for the original id means it committed);
                <comment>2</comment> nothing was attempted and the command line needs fixing — a malformed
                id, or a confirmation this run could not put. Do not retry on <comment>2</comment>.
                A run that cannot be asked (<comment>--no-interaction</comment>, a closed or already-exhausted
                stdin, <comment>--quiet</comment>, <comment>--silent</comment>, or a negative
                <comment>SHELL_VERBOSITY</comment>) needs <comment>--force</comment> to erase.
                <comment>--dry-run</comment> answers <comment>1</comment> too when the count itself fails.

                  <info>php %command.full_name% <actor-id> --dry-run</info>
                  <info>php %command.full_name% <actor-id></info>
                  <info>php %command.full_name% <actor-id> --force</info>
                HELP)
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $actorId = $input->getArgument('actor-id');

        if (!\is_string($actorId) || !Uuid::isValid($actorId)) {
            $io->error('The actor-id must be a valid UUID.');

            return Command::INVALID;
        }

        $io->writeln(\sprintf('Actor:        %s', $actorId));

        $preflight = $this->preflight($io, $input, $actorId);

        if (null !== $preflight) {
            return $preflight;
        }

        return $this->eraseAndReport($io, $actorId);
    }

    /**
     * The four modes a run can be in, in the order the two sibling erasure commands use: the no-op the
     * operator asked for, then the run that says up front it needs no answer, then the run that cannot be
     * asked, then the question itself. Written as a table rather than a sequence of guards because the
     * precedence is the contract — a run that asked for a preview gets one even when it also passed
     * <comment>--force</comment>.
     *
     * **The row count is read only on the paths that consume it, and the table is what keeps that true.** It
     * feeds the confirmation's magnitude and it is the whole point of `--dry-run`; a `--force` run does not
     * need it (the erasure's own `affectedRows` is the authoritative figure) and a run the predicate can
     * already refuse must not compute it at all, because a refusal that reads the subject first is a refusal
     * that reports on it.
     *
     * **The arm reaches the shapes {@see UnattendedRunPolicy::cannotAnswer()} can see, and only those.** A
     * stdin that is empty but not yet read has `\feof()` false, so the predicate admits it and the count is
     * taken before the question finds out. What keeps that harmless is that {@see self::confirmMatchedRows()}
     * puts the question whatever the count says.
     *
     * @return int|null the exit code to stop on, or null to proceed with the anonymisation
     */
    private function preflight(SymfonyStyle $io, InputInterface $input, string $actorId): ?int
    {
        return match (true) {
            true === $input->getOption('dry-run') => $this->reportDryRun($io, $actorId),
            true === $input->getOption('force') => null,
            UnattendedRunPolicy::cannotAnswer($input) => $this->refuseUnaskableRun($io),
            default => $this->confirmMatchedRows($io, $input, $actorId),
        };
    }

    /** One sentence for both shapes of unanswered question, so neither can drift away from the other. */
    private function refuseUnaskableRun(SymfonyStyle $io): int
    {
        return UnattendedRunPolicy::refuse($io, 'anonymise', 'the matching row count', 'No rows were changed.');
    }

    /** The one no-op the operator expressed, and the only path that reads the trail without meaning to change it. */
    private function reportDryRun(SymfonyStyle $io, string $actorId): int
    {
        $matched = $this->reportMatches($io, $actorId);

        if (null === $matched) {
            return Command::FAILURE;
        }

        if (0 === $matched) {
            return $this->reportNothingToErase($io);
        }

        $io->note('Dry run: no rows were changed.');

        return Command::SUCCESS;
    }

    /**
     * The magnitude the operator is about to act on, and the one reading that leaves nothing to ask about: a
     * trail that could not be counted at all.
     *
     * **The question is put whatever the count says, and that is what keeps the exit code independent of the
     * trail.** A run whose stdin is empty but unread arrives here looking answerable — `\feof()` is false
     * until something has read — and only the re-read after `confirm()` finds out otherwise. An early return
     * on an empty trail would hand that run an exit code decided by a count it never answered for: `SUCCESS`
     * for an actor with no rows against `INVALID` for one with them, which is an existence oracle over an
     * actor id and readable by a caller that discards stdout.
     *
     * @return int|null the exit code to stop on, or null to proceed with the anonymisation
     */
    private function confirmMatchedRows(SymfonyStyle $io, InputInterface $input, string $actorId): ?int
    {
        $matched = $this->reportMatches($io, $actorId);

        if (null === $matched) {
            return Command::FAILURE;
        }

        return $this->confirmAnonymisationOf($io, $input, $matched);
    }

    /**
     * The question and the outcomes it can produce, as a table because the demotion's precedence over every
     * other outcome is the contract: when the helper answers with its own default, `$confirmed` is `false`,
     * so an arm above it would report a rejection the operator expressed for a question nobody heard. A "no"
     * they did type is such a rejection, so it succeeds; a question this run could never put is not, and
     * reporting success there is indistinguishable from a completed erasure to a caller reading `$?`.
     *
     * **What an affirmative answer authorises is the statement, never a decision taken from the preview.**
     * The count was read before the operator answered and an operator takes as long as they take, so rows
     * can arrive in between; deciding here that there is nothing left would skip the `UPDATE` on a figure
     * that has since gone stale, and report success over rows the operator had just agreed to destroy.
     * {@see self::recordErasure()} owns that decision, from `affectedRows` — the same figure the two sibling
     * erasures decide it from, and in the same place: beside the write it defends.
     *
     * The demotion is the shape only a re-read can see: a stdin nothing can be read from enters the question
     * interactive and leaves it demoted, because the helper answers with the default it was handed rather
     * than raising. {@see UnattendedRunPolicy::cannotAnswer()} covers the other two shapes and says why it
     * cannot cover this one, which is why nothing may come between the question and the re-read.
     *
     * @return int|null the exit code to stop on, or null to proceed with the anonymisation
     */
    private function confirmAnonymisationOf(SymfonyStyle $io, InputInterface $input, int $matched): ?int
    {
        $confirmed = $io->confirm(\sprintf('Irreversibly anonymise %d row(s)?', $matched), false);

        return match (true) {
            !$input->isInteractive() => $this->refuseUnaskableRun($io),
            !$confirmed => $this->reportAbortedByOperator($io),
            default => null,
        };
    }

    /** The rejection the operator expressed, which is the no-op they asked for rather than a failure. */
    private function reportAbortedByOperator(SymfonyStyle $io): int
    {
        $io->warning('Aborted — no rows were changed.');

        return Command::SUCCESS;
    }

    /**
     * The row count, or null when the trail could not be read. A failure here is `FAILURE` and not `INVALID`:
     * a database that cannot answer is exactly what a caller should retry, and `INVALID` is the one code this
     * command's contract tells it never to retry on.
     *
     * @return int|null the matching row count, or null when the count could not be taken
     */
    private function reportMatches(SymfonyStyle $io, string $actorId): ?int
    {
        try {
            $matched = $this->anonymiser->countFor($actorId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf(
                'Could not read the trail: %s. Nothing was attempted — this run is safe to repeat with the '
                . 'same id.',
                $throwable->getMessage(),
            ));

            return null;
        }

        $io->writeln(\sprintf('Rows matched: %d', $matched));

        return $matched;
    }

    private function reportNothingToErase(SymfonyStyle $io): int
    {
        $io->warning('No audit rows for that actor — nothing to erase.');

        return Command::SUCCESS;
    }

    /**
     * The irreversible half: change the rows and record that it happened, as one transaction. It carries its own
     * `catch` and does not merely lose a distinction without one — the throwable would escape `execute()`,
     * and the console derives the process exit code from `Throwable::getCode()`, so a DBAL driver code
     * surfaces as that number, or as 255 once it exceeds the byte, and a caller reading `$?` sees neither
     * `1` nor anything it can map.
     *
     * A failure here answers `FAILURE` and means the transaction rolled back, with one exception the message
     * names: a connection lost at the `COMMIT` can commit without acknowledging, which is why it says to
     * verify with `--dry-run` rather than to repeat the run blindly.
     */
    private function eraseAndReport(SymfonyStyle $io, string $actorId): int
    {
        try {
            $result = $this->eraseActorAuditTrail->erase($actorId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf(
                'Erasure failed: %s. The rows and their compliance entry are written together, so they keep their '
                . 'original id and this run is safe to repeat — unless the connection was lost at the commit, which '
                . 'only --dry-run can settle: zero rows for this id means it committed.',
                $throwable->getMessage(),
            ));

            return Command::FAILURE;
        }

        // An UPDATE that matched nothing is not an erasure, and the use case wrote no evidence for it. Reached
        // from `--force`, which takes no preview, and from a confirmed run whose rows were pruned or anonymised
        // between the count and the statement — a preview is only ever approximate by the time it runs.
        if (0 === $result->affectedRows) {
            return $this->reportNothingToErase($io);
        }

        $io->success(\sprintf(
            'Erased %d row(s). New anonymised actor id: %s',
            $result->affectedRows,
            $result->pseudonym,
        ));

        return Command::SUCCESS;
    }
}
