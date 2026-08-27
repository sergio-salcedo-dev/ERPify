<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditErasureEvidence;
use Erpify\Shared\Audit\Domain\AuditLevel;
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
 * **Exit codes are this command's contract with an unattended caller**, which reads `$?` and never the screen:
 * `SUCCESS` means the trail is anonymised, or that the no-op the operator asked for (no matching rows,
 * `--dry-run`, or a confirmation answered "no") happened; `FAILURE` means the erasure did not complete, and
 * **the message says whether it started** — the `UPDATE` failing changed nothing and is safe to repeat, while
 * the `UPDATE` succeeding and its compliance self-audit failing leaves an erasure that is done, irreversible,
 * and unrecorded; `INVALID` means nothing was attempted and the command line is what needs repairing — a
 * malformed id, or a confirmation this run could not put. `INVALID` is the one code a caller must not retry
 * on. `--quiet` and `--silent` imply `--no-interaction` AND suppress the refusal's own message, so an
 * unattended run that means to erase passes `--force`.
 */
#[AsCommand(
    name: 'audit:gdpr:erase',
    description: "Irreversibly anonymise one actor's audit trail (GDPR right to erasure)",
)]
final class EraseActorAuditTrailCommand extends Command
{
    private const string ERASURE_ACTION = AuditErasureEvidence::ACTOR_TRAIL_ERASED;

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

                Exit codes: <comment>0</comment> anonymised, or the no-op you asked for;
                <comment>1</comment> the erasure did not complete — read the message, which says whether it
                started; <comment>2</comment> nothing was attempted and the command
                line needs fixing — a malformed id, or a confirmation this run could not put. Do not retry on
                <comment>2</comment>. A run that cannot be asked (<comment>--no-interaction</comment>, a closed
                stdin, <comment>--quiet</comment>, <comment>--silent</comment>) needs <comment>--force</comment>
                to erase.

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

        $matched = $this->anonymiser->countFor($actorId);
        $io->writeln(\sprintf('Actor:        %s', $actorId));
        $io->writeln(\sprintf('Rows matched: %d', $matched));

        if (0 === $matched) {
            $io->warning('No audit rows for that actor — nothing to erase.');

            return Command::SUCCESS;
        }

        if (true === $input->getOption('dry-run')) {
            $io->note('Dry run: no rows were changed.');

            return Command::SUCCESS;
        }

        $preflight = $this->confirmUnlessForced($io, $input, $matched);

        if (null !== $preflight) {
            return $preflight;
        }

        return $this->eraseAndReport($io, $actorId);
    }

    /**
     * The confirmation and the two ways it can go unanswered. A "no" the operator typed is a rejection they
     * expressed, so it succeeds; a question this run could never put is a rejection nobody expressed, and
     * reporting success there is indistinguishable from a completed erasure to a caller reading `$?`.
     *
     * @return int|null the exit code to stop on, or null to proceed with the anonymisation
     */
    private function confirmUnlessForced(SymfonyStyle $io, InputInterface $input, int $matched): ?int
    {
        if (true === $input->getOption('force')) {
            return null;
        }

        if (!$input->isInteractive()) {
            return $this->refuseUnattended($io);
        }

        $confirmed = $io->confirm(\sprintf('Irreversibly anonymise %d row(s)?', $matched), false);

        // A stdin nothing can be read from — EOF, an empty pipe — enters the question interactive and leaves
        // it demoted: the question helper answers with the default it was handed and turns the input
        // non-interactive instead of raising. Reading the flag a second time is therefore what separates a
        // typed "no" from a question nobody was there to hear. It reaches a stdin that yields NOTHING, not
        // one that yields not-yes: a pipe carrying a blank line is an answer, and accepts the default.
        //
        // This is load-bearing rather than belt-and-braces. The console decides interactivity from the flags
        // alone and never asks whether it is attached to a terminal, so an unattended run that omits
        // `--no-interaction` arrives here still interactive and this read is the only thing in front of it.
        if (!$input->isInteractive()) {
            return $this->refuseUnattended($io);
        }

        if (!$confirmed) {
            $io->warning('Aborted — no rows were changed.');

            return Command::SUCCESS;
        }

        return null;
    }

    private function refuseUnattended(SymfonyStyle $io): int
    {
        $io->error(
            'Refusing to anonymise: this run cannot ask for a confirmation (--no-interaction, or stdin '
            . 'closed) and no confirmation was given. Pass --force to erase unattended, or --dry-run to '
            . 'report the matching row count without touching it. No rows were changed.',
        );

        return Command::INVALID;
    }

    /**
     * The two failures here are not the same failure, and both answer `FAILURE` because the exit code alone
     * cannot separate them — the message must. If the `UPDATE` itself fails nothing was changed and the run
     * is safe to repeat with the original id; if it succeeded and only the compliance self-audit failed, the
     * erasure is done and irreversible and repeating it with the original id would report "nothing to erase"
     * over a subject that was in fact erased.
     *
     * The anonymisation therefore carries its own `catch`. Leaving it outside one does not merely lose the
     * distinction: the throwable escapes `execute()`, and the console derives the process exit code from
     * `Throwable::getCode()` — so a DBAL driver code surfaces as that number, or as 255 once it exceeds the
     * byte, and a caller reading `$?` sees neither `1` nor anything it can map.
     */
    private function eraseAndReport(SymfonyStyle $io, string $actorId): int
    {
        try {
            $result = $this->anonymiser->anonymise($actorId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf(
                'Anonymisation failed: %s. No rows were changed — this run is safe to repeat with the same id.',
                $throwable->getMessage(),
            ));

            return Command::FAILURE;
        }

        try {
            $this->auditLogger->log(self::ERASURE_ACTION, AuditLevel::SECURITY, null, [
                'anonymized_actor_id' => $result->pseudonym,
                'affected_rows' => $result->affectedRows,
            ]);
        } catch (Throwable $throwable) {
            // The anonymisation already committed and is irreversible; only the compliance self-audit
            // failed. The original id now matches nothing, so a naive re-run would falsely report
            // "nothing to erase" — surface the gap so the operator records this erasure out of band.
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

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Erased %d row(s). New anonymised actor id: %s',
            $result->affectedRows,
            $result->pseudonym,
        ));

        return Command::SUCCESS;
    }
}
