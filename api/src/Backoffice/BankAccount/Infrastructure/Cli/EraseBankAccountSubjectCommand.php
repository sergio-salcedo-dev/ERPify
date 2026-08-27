<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Cli;

use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
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
 * GDPR "right to erasure" for a bank-account subject: removes the live record and destroys its encryption
 * scope's key so the PII in the append-only trail becomes permanently unreadable. Distinct from
 * <comment>audit:gdpr:erase</comment> (actor erasure) — the two GDPR triggers are never merged (ADR D15).
 *
 * A console command rather than an HTTP route because there is no authentication yet — an unauthenticated
 * erasure endpoint would be a footgun. Synchronous: the scope is one subject, not a sweep.
 *
 * **Exit codes are this command's contract with an unattended caller**, which reads `$?` and never the screen:
 * `SUCCESS` means the subject is erased, or that the no-op the operator asked for (`--dry-run`, or a
 * confirmation answered "no") happened; `FAILURE` means the erasure was attempted and did not complete;
 * `INVALID` means no erasure was attempted and the command line is what needs repairing — a malformed id, or
 * a confirmation this run could not put. `INVALID` is the one code a caller must not retry on. What makes the
 * distinction matter more here than in a reversible command: the key destruction is a crypto-shred, so a run
 * that reports success without performing it leaves the subject's PII readable while the compliance record
 * says otherwise. `--quiet` and `--silent` imply `--no-interaction` AND suppress the refusal's own message,
 * so an unattended run that means to erase passes `--force`.
 */
#[AsCommand(
    name: 'bank-account:gdpr:erase-subject',
    description: "Irreversibly erase a bank-account subject's data (GDPR right to erasure)",
)]
final class EraseBankAccountSubjectCommand extends Command
{
    public function __construct(
        private readonly EraseBankAccountSubject $eraser,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('bank-account-id', InputArgument::REQUIRED, 'The bank account id (UUID) to erase')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the target without mutating anything')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command removes a bank account and destroys its encryption
                scope's key, so the personal data (<comment>holderName</comment>/<comment>iban</comment>) in
                the append-only <comment>audit_log</comment> becomes permanently unreadable. Audit rows are
                never deleted — the trail survives, its PII just becomes unrecoverable.

                Exit codes: <comment>0</comment> erased, or the no-op you asked for; <comment>1</comment> the
                erasure was attempted and failed; <comment>2</comment> nothing was attempted and the command
                line needs fixing — a malformed id, or a confirmation this run could not put. Do not retry on
                <comment>2</comment>. A run that cannot be asked (<comment>--no-interaction</comment>, a closed
                stdin, <comment>--quiet</comment>, <comment>--silent</comment>) needs <comment>--force</comment>
                to erase.

                  <info>php %command.full_name% <bank-account-id> --dry-run</info>
                  <info>php %command.full_name% <bank-account-id></info>
                  <info>php %command.full_name% <bank-account-id> --force</info>
                HELP)
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $bankAccountId = $input->getArgument('bank-account-id');

        if (!\is_string($bankAccountId) || !Uuid::isValid($bankAccountId)) {
            $io->error('The bank-account-id must be a valid UUID.');

            return Command::INVALID;
        }

        $io->writeln(\sprintf('Bank account: %s', $bankAccountId));

        $preflight = $this->preflight($io, $input);

        if (null !== $preflight) {
            return $preflight;
        }

        return $this->eraseAndReport($io, $bankAccountId);
    }

    /**
     * Pre-flight guards that stop before any mutation, each carrying the exit code its own outcome earns.
     * Three outcomes, and they are not one: a dry run is the no-op the operator asked for; a confirmation
     * answered "no" is a rejection the operator expressed; and a run that could never put the question is a
     * rejection nobody expressed, which is the only one that must not report success.
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
            'Irreversibly erase this subject (removes the account, shreds its audit PII)?',
            false,
        );

        // A stdin nothing can be read from — EOF, an empty pipe — enters the question interactive and leaves
        // it demoted: the question helper answers with the default it was handed and turns the input
        // non-interactive instead of raising. Reading the flag a second time is therefore what separates a
        // typed "no" from a question nobody was there to hear.
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

    private function eraseAndReport(SymfonyStyle $io, string $bankAccountId): int
    {
        try {
            $result = $this->eraser->execute($bankAccountId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Erasure failed: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        if (!$result->erasedAnything()) {
            $io->warning('Nothing to erase — the subject has no live record and no key.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            'Erased subject %s (live record removed: %s, key destroyed: %s).',
            $result->encryptionScopeId,
            $result->liveRecordErased ? 'yes' : 'no',
            $result->keyDestroyed ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    }
}
