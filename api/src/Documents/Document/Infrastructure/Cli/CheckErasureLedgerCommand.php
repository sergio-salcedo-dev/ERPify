<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Cli;

use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\LedgerUnreadable;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Proves the erasure ledger readable in full — every byte of every entry, not only its listing — and changes nothing.
 * What the restore drill and an operator ask before trusting a ledger: exit 0 when it is, non-zero when it is not.
 */
#[AsCommand(
    name: 'documents:erasure:check-ledger',
    description: 'Prove the erasure ledger readable in full (read-only; takes no argument)',
)]
final class CheckErasureLedgerCommand extends Command
{
    public function __construct(
        private readonly ErasureLedger $ledger,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->ledger->verifyReadableInFull();
            $count = \count($this->ledger->entries());
        } catch (LedgerUnreadable) {
            $io->error('The erasure ledger cannot be read in full.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('The erasure ledger is readable in full (%d scope(s)).', $count));

        return Command::SUCCESS;
    }
}
