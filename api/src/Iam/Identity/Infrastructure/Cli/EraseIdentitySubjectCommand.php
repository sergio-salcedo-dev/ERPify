<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
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
 * GDPR "right to erasure" for an identity subject, run as one chained operation through {@see FulfilIdentityErasure}:
 * it hard-deletes the user row (the module's PII — email and credential hash) and every pending password-reset
 * token, anonymises every audit row the subject authored and every one that names them, rewrites their
 * identifier out of the reproducible business log, and hard-deletes the subject's sessions, the
 * membership that admitted them and every invitation addressed to them — atomically —
 * leaving `GDPR_SUBJECT_ERASED` and `GDPR_ERASURE_EXECUTED` security entries as the compliance record. Because it
 * shares that use case with the identity console, the CLI also enforces the ≥1-active-administrator guard (erasing
 * the last active admin fails). The `system` actor it runs as carries no id, so the self-erasure refusal never
 * applies. The additive `audit:gdpr:erase` command remains for anonymising an actor's trail without erasing the
 * identity.
 *
 * **Exit codes are this command's contract with an unattended caller**, which reads `$?` and never the screen:
 * `SUCCESS` means the subject is erased, or that the no-op the operator asked for (`--dry-run`, or a
 * confirmation answered "no") happened; `FAILURE` means the erasure was attempted and did not complete;
 * `INVALID` means no erasure was attempted and the command line is what needs repairing — a malformed id, or
 * a confirmation this run could not put. `INVALID` is therefore the one code a caller must not retry on.
 * `--quiet` and `--silent` imply `--no-interaction` AND suppress the refusal's own message, so an unattended
 * run that means to erase passes `--force`.
 */
#[AsCommand(
    name: 'identity:gdpr:erase-subject',
    description: "Irreversibly erase an identity subject's data and audit trail (GDPR right to erasure)",
)]
final class EraseIdentitySubjectCommand extends Command
{
    public function __construct(
        private readonly FulfilIdentityErasure $eraser,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('user-id', InputArgument::REQUIRED, 'The user id (UUID) to erase')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the target without mutating anything')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command hard-deletes an identity (its email and credential
                hash) together with every pending password-reset token, anonymises every audit row the subject
                authored and every one that names it, rewrites its identifier out of the reproducible business
                log (<comment>event_store</comment>, in the aggregate column and inside the stored JSON alike)
                and hard-deletes its sessions, its organization membership and every invitation
                addressed to it — atomically — so no <comment>user_id</comment> linkage, recovery artefact or
                residual session PII outlives the subject. The erasure self-audits as
                <comment>GDPR_SUBJECT_ERASED</comment> and <comment>GDPR_ERASURE_EXECUTED</comment> security
                entries, and is refused if the subject is the last active administrator.

                Exit codes: <comment>0</comment> erased, or the no-op you asked for; <comment>1</comment> the
                erasure was attempted and failed; <comment>2</comment> nothing was attempted and the command
                line needs fixing — a malformed id, or a confirmation this run could not put. Do not retry on
                <comment>2</comment>. A run that cannot be asked (<comment>--no-interaction</comment>, a closed
                stdin, <comment>--quiet</comment>, <comment>--silent</comment>) needs <comment>--force</comment>
                to erase.

                  <info>php %command.full_name% <user-id> --dry-run</info>
                  <info>php %command.full_name% <user-id></info>
                  <info>php %command.full_name% <user-id> --force</info>
                HELP)
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = $input->getArgument('user-id');

        if (!\is_string($userId) || !Uuid::isValid($userId)) {
            $io->error('The user-id must be a valid UUID.');

            return Command::INVALID;
        }

        $io->writeln(\sprintf('Identity subject: %s', $userId));

        $preflight = $this->preflight($io, $input);

        if (null !== $preflight) {
            return $preflight;
        }

        return $this->eraseAndReport($io, $userId);
    }

    /**
     * Pre-flight guards that stop before any mutation, each carrying the exit code its own outcome earns —
     * the contract of an erasure command is its exit code, because a compliance job reads `$?` and nothing
     * else. Three outcomes, and they are not one:
     *
     * - a dry run is the no-op the operator asked for, so it succeeds;
     * - a confirmation answered "no" is a rejection the operator expressed — they were asked, they replied,
     *   and the command did exactly what they said, so it succeeds too;
     * - a run that could never put the question is a rejection nobody expressed. Reporting success there is
     *   indistinguishable from a completed erasure to every caller that is not a human reading the screen.
     *
     * That last one refuses with `INVALID` rather than `FAILURE`, the same reading the malformed user-id
     * above gives it and the one {@see ReconcileErasedSubjectReferencesCommand} gives it: no erasure was
     * attempted, so there is no half-run state to reason about and the repair belongs on the command line.
     * `FAILURE` would send whoever reads `$?` looking for an erasure that half-ran. The shared reading is
     * "no verdict was reached, do not act on it" rather than "nothing is broken" — the reconciler answers
     * `INVALID` for a failed probe too, which is a fault.
     *
     * @return int|null the exit code to stop on, or null to proceed with the erasure
     */
    private function preflight(SymfonyStyle $io, InputInterface $input): ?int
    {
        if (true === $input->getOption('dry-run')) {
            $io->note('Dry run: nothing was erased.');

            return Command::SUCCESS;
        }

        if (true === $input->getOption('force')) {
            return null;
        }

        if (!$input->isInteractive()) {
            return $this->refuseUnattended($io);
        }

        $confirmed = $io->confirm(
            'Irreversibly erase this identity (removes the user and its reset tokens, anonymises its audit '
            . 'trail, rewrites its identifier out of the business event log, and drops its sessions, its '
            . 'organization membership and every invitation addressed to it)?',
            false,
        );

        // A stdin nothing can be read from — EOF, an empty pipe — enters the question interactive and leaves
        // it demoted: the question helper answers with the default it was handed and turns the input
        // non-interactive instead of raising. Reading the flag a second time is therefore what separates a
        // typed "no" from a question nobody was there to hear, and without it the confirmation's own
        // false-by-default is the operator's answer as far as this method can tell. It reaches a stdin that
        // yields NOTHING, not one that yields not-yes: a pipe carrying a blank line is an answer, and
        // accepts the default.
        //
        // This is load-bearing rather than belt-and-braces. The console decides interactivity from the flags
        // alone and never asks whether it is attached to a terminal, so an unattended run that omits
        // `--no-interaction` arrives here still interactive and this read is the only thing in front of it.
        if (!$input->isInteractive()) {
            return $this->refuseUnattended($io);
        }

        if (!$confirmed) {
            $io->warning('Aborted — nothing was erased.');

            return Command::SUCCESS;
        }

        return null;
    }

    private function refuseUnattended(SymfonyStyle $io): int
    {
        $io->error(
            'Refusing to erase: this run cannot ask for a confirmation (--no-interaction, or stdin closed) '
            . 'and no confirmation was given. Pass --force to erase unattended, or --dry-run to report the '
            . 'target without touching it. Nothing was erased.',
        );

        return Command::INVALID;
    }

    private function eraseAndReport(SymfonyStyle $io, string $userId): int
    {
        try {
            $result = $this->eraser->execute($userId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Erasure failed: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        if (!$result->erasedAnything()) {
            $io->warning(
                'Nothing to erase — the subject has no live identity, pending reset tokens, audit trail, '
                . 'sessions, membership or invitations.',
            );

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            'Erased subject %s (identity removed: %s, reset tokens deleted: %d, audit rows authored '
            . 'anonymised: %d, audit rows naming the subject anonymised: %d, business-log rows '
            . 'anonymised: %d, sessions removed: %d, memberships removed: %d, invitations removed: %d).',
            $userId,
            $result->identityErased ? 'yes' : 'no',
            $result->resetTokensDeleted,
            $result->anonymizedAuditRows,
            $result->anonymizedResourceRows,
            $result->anonymizedEventRows,
            $result->sessionsDeleted,
            $result->membershipsDeleted,
            $result->invitationsDeleted,
        ));

        return Command::SUCCESS;
    }
}
