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

                  <info>php %command.full_name% <bank-account-id> --dry-run</info>
                  <info>php %command.full_name% <bank-account-id></info>
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

        if (true === $input->getOption('dry-run')) {
            $io->note('Dry run: nothing was erased.');

            return Command::SUCCESS;
        }

        if (
            true !== $input->getOption('force')
            && !$io->confirm('Irreversibly erase this subject (removes the account, shreds its audit PII)?', false)
        ) {
            $io->warning('Aborted — nothing was erased.');

            return Command::SUCCESS;
        }

        return $this->eraseAndReport($io, $bankAccountId);
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
