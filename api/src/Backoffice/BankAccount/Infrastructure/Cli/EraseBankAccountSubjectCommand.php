<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Cli;

use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
use Erpify\Shared\Console\Infrastructure\ConfirmedErasureCommand;
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
 * `SUCCESS` means the subject is erased, that the no-op the operator asked for (`--dry-run`, or a
 * confirmation answered "no") happened, or that there was nothing left to erase — a valid id naming no live
 * subject, which is where a typo'd-but-well-formed id lands; `FAILURE` means the erasure was attempted and
 * did not complete;
 * `INVALID` means no erasure was attempted and the command line is what needs repairing — a malformed id, or
 * a confirmation this run could not put. `INVALID` is the one code a caller must not retry on. What makes the
 * distinction matter more here than in a reversible command: the key destruction is a crypto-shred, so a run
 * that reports success without performing it leaves the subject's PII readable while the compliance record
 * says otherwise.
 *
 * Three spellings suppress the refusal's own message while still refusing: `--quiet`, `--silent`, and a
 * negative `SHELL_VERBOSITY` inherited from a parent process. An unattended run that means to erase passes
 * `--force`. What no exit code here can cover is a command line the console cannot **bind** — an unknown
 * option, a wrong arity, a mistyped name — which raises before `execute()` and exits `1`, so "never retry on
 * `INVALID`" is a floor on retries rather than a partition of them.
 */
#[AsCommand(
    name: 'bank-account:gdpr:erase-subject',
    description: "Irreversibly erase a bank-account subject's data (GDPR right to erasure)",
)]
final class EraseBankAccountSubjectCommand extends ConfirmedErasureCommand
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

                Exit codes: <comment>0</comment> erased, the no-op you asked for, or nothing left to
                erase; <comment>1</comment> the erasure was attempted and failed; <comment>2</comment> nothing
                was attempted and the command line needs fixing — a malformed id, or a confirmation this run
                could not put. Do not retry on <comment>2</comment>. A run that cannot be asked
                (<comment>--no-interaction</comment>, a closed or already-exhausted stdin,
                <comment>--quiet</comment>, <comment>--silent</comment>, or a negative
                <comment>SHELL_VERBOSITY</comment>) needs <comment>--force</comment> to erase.

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

    #[Override]
    protected function confirmationQuestion(): string
    {
        return 'Irreversibly erase this subject (removes the account, shreds its audit PII)?';
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
