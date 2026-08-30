<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditErasureEvidence;
use Erpify\Shared\Audit\Domain\AuditLevel;
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
 * original id), so the act of forgetting is provable for compliance without re-identifying the person.
 *
 * **Exit codes are this command's contract with an unattended caller**, which reads `$?` and never the screen.
 * `SUCCESS` means the trail is anonymised, or that a no-op happened — no matching rows, `--dry-run`, or a
 * confirmation answered "no". `FAILURE` means the erasure did not complete: either the count failed, so
 * nothing was attempted, or the `UPDATE` failed, so the rows may or may not have changed — a connection lost
 * mid-statement can commit without acknowledging — which is why the message says how far it got and only the
 * first is unconditionally safe to repeat. {@see ERASED_UNRECORDED} is the opposite outcome and carries its
 * own code because no message can reach a caller reading `$?`: the erasure IS done, irreversibly, and
 * nothing attests it. `INVALID` means nothing was attempted and the command line is what needs repairing —
 * a malformed id, or a confirmation this run could not put — and it is the one code a caller must not retry
 * on.
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
    private const string ERASURE_ACTION = AuditErasureEvidence::ACTOR_TRAIL_ERASED;

    /**
     * The erasure committed and its compliance self-audit did not. It earns a code of its own because it is
     * the one outcome a caller must treat differently from every other failure and cannot tell apart by
     * reading `$?` alone: the rows ARE anonymised, irreversibly, and no `GDPR_ERASURE_EXECUTED` row attests
     * it — so retrying with the original id matches nothing and reports "nothing to erase" over a subject
     * that was in fact erased. What it asks for is not a retry but a hand-written compliance record.
     *
     * Outside `Command`'s own vocabulary on purpose: `FAILURE` already means "the erasure did not complete",
     * and this is its opposite. A job that does not know the code sees a non-zero and stops, which is the
     * safe reading.
     */
    public const int ERASED_UNRECORDED = 3;

    public function __construct(
        private readonly AuditActorAnonymiser $anonymiser,
        private readonly AuditLogger $auditLogger,
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

                Exit codes: <comment>0</comment> anonymised, or the no-op you asked for (including an
                actor with no matching rows); <comment>1</comment> the erasure did not complete — read the
                message, which says how far it got, since only a failed row count is unconditionally safe to
                repeat; <comment>3</comment> the rows WERE anonymised and the compliance entry recording it
                was not — do not retry, record the erasure by hand; <comment>2</comment> nothing was
                attempted and the command line needs fixing — a
                malformed id, or a confirmation this run could not put. Do not retry on <comment>2</comment>.
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
     * need it (the erasure's own `affectedRows` is the authoritative figure) and a run about to be refused
     * would compute it and throw it away — which is what made the exit code an existence oracle over an
     * actor id, answering `2` for an actor with rows and `0` for one without.
     *
     * **The arm closes that for the shapes {@see UnattendedRunPolicy::cannotAnswer()} can see, and only
     * those.** A stdin that is empty but not yet READ has `\feof()` false, so the predicate admits it and
     * the run reaches the count before the question demotes it: measured, such a run still answers `2` for
     * an actor with rows and `0` for one without, and still reads the trail it was refused. Closing that
     * one costs the prompt its magnitude — the only defect an operator can catch before an irreversible
     * `UPDATE` — or asks a subject with nothing left to erase to confirm erasing it, so it is a residual
     * this command carries knowingly rather than a hole nobody noticed.
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
     * The magnitude the operator is about to act on, and the two readings that leave nothing to ask about:
     * a trail that could not be counted, and a subject whose rows are already gone.
     *
     * @return int|null the exit code to stop on, or null to proceed with the anonymisation
     */
    private function confirmMatchedRows(SymfonyStyle $io, InputInterface $input, string $actorId): ?int
    {
        $matched = $this->reportMatches($io, $actorId);

        if (null === $matched) {
            return Command::FAILURE;
        }

        if (0 === $matched) {
            return $this->reportNothingToErase($io);
        }

        return $this->confirmAnonymisationOf($io, $input, $matched);
    }

    /**
     * The question, and the two ways it can go unanswered. A "no" the operator typed is a rejection they
     * expressed, so it succeeds; a question this run could never put is a rejection nobody expressed, and
     * reporting success there is indistinguishable from a completed erasure to a caller reading `$?`.
     *
     * That second shape is the one only a re-read can see: a stdin nothing can be read from enters the
     * question interactive and leaves it demoted, because the helper answers with the default it was handed
     * rather than raising. Reading the flag again immediately after is what separates a typed "no" from a
     * question nobody was there to hear — {@see UnattendedRunPolicy::cannotAnswer()} covers the other two
     * shapes and says why it cannot cover this one, which is why nothing may come between the two.
     *
     * @return int|null the exit code to stop on, or null to proceed with the anonymisation
     */
    private function confirmAnonymisationOf(SymfonyStyle $io, InputInterface $input, int $matched): ?int
    {
        $confirmed = $io->confirm(\sprintf('Irreversibly anonymise %d row(s)?', $matched), false);

        if (!$input->isInteractive()) {
            return $this->refuseUnaskableRun($io);
        }

        if (!$confirmed) {
            $io->warning('Aborted — no rows were changed.');

            return Command::SUCCESS;
        }

        return null;
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
     * The irreversible half: change the rows, then hand the outcome on to be recorded. It carries its own
     * `catch` and does not merely lose a distinction without one — the throwable would escape `execute()`,
     * and the console derives the process exit code from `Throwable::getCode()`, so a DBAL driver code
     * surfaces as that number, or as 255 once it exceeds the byte, and a caller reading `$?` sees neither
     * `1` nor anything it can map.
     *
     * A failure here answers `FAILURE`, and `FAILURE` is not a promise that nothing changed: a connection
     * lost mid-statement can commit without acknowledging, which is why the message says to verify with
     * `--dry-run` rather than to repeat the run. Only a failed row COUNT is unconditionally safe to repeat.
     */
    private function eraseAndReport(SymfonyStyle $io, string $actorId): int
    {
        try {
            $result = $this->anonymiser->anonymise($actorId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf(
                'Anonymisation failed: %s. The rows may or may not have been changed — a lost connection '
                . 'can commit without acknowledging — so verify with --dry-run before repeating this run.',
                $throwable->getMessage(),
            ));

            return Command::FAILURE;
        }

        return $this->recordErasure($io, $result);
    }

    /**
     * The compliance self-audit, and everything it can do is on the far side of a commit that cannot be
     * undone. Its failure is not the same failure as a failed `UPDATE`, and the exit code alone cannot
     * separate them — the message must: the rows ARE anonymised, the original id now matches nothing, and
     * a naive re-run would falsely report "nothing to erase" over a subject that was in fact erased. So it
     * answers with a code of its own and tells the operator to record the erasure out of band.
     *
     * The zero-rows guard lives HERE, in the same method as the write it defends, rather than in the
     * caller: a seam between them is a seam a future second caller can enter on the wrong side.
     */
    private function recordErasure(SymfonyStyle $io, ActorAnonymisationResult $result): int
    {
        // An UPDATE that matched nothing is not an erasure, and saying so before the self-audit is what
        // keeps the evidence honest: `AuditErasureEvidence` exempts this action from the retention prune
        // for ever, so a row written here for a subject that was already anonymised is an immortal claim
        // that an erasure happened. Reachable only from `--force`, which skips the preview by design.
        if (0 === $result->affectedRows) {
            return $this->reportNothingToErase($io);
        }

        try {
            $this->auditLogger->log(self::ERASURE_ACTION, AuditLevel::SECURITY, null, [
                'anonymized_actor_id' => $result->pseudonym,
                'affected_rows' => $result->affectedRows,
            ]);
        } catch (Throwable $throwable) {
            $io->error([
                \sprintf(
                    'Anonymised %d row(s) to actor id %s, but recording the %s audit entry failed: %s',
                    $result->affectedRows,
                    $result->pseudonym,
                    self::ERASURE_ACTION,
                    $throwable->getMessage(),
                ),
                'The erasure is done and irreversible — do not re-run with the original id. '
                . 'Record this erasure manually for compliance.',
            ]);

            return self::ERASED_UNRECORDED;
        }

        $io->success(\sprintf(
            'Erased %d row(s). New anonymised actor id: %s',
            $result->affectedRows,
            $result->pseudonym,
        ));

        return Command::SUCCESS;
    }
}
