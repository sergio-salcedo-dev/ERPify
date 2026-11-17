<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Cli;

use Erpify\Documents\Document\Application\Erasure\ErasureOverrideSwitch;
use Erpify\Documents\Document\Domain\Erasure\LedgerOverrideReason;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sets the erasure ledger's override: the documented way a restore proceeds although the ledger cannot be read in full,
 * accepting that a key destroyed since the backup may have come back. From that moment no document is read, listed or
 * trailed until a completion run has replayed a readable ledger and found nothing not erased.
 *
 * The one argument is a closed reason, never a name; the row holds no operator identity. The command prints the random
 * `record_ref` the operator writes in the off-host operator log, beside their own identity and the date — that log is
 * where "who" lives, and the only place it can be deleted from when its retention ends.
 */
#[AsCommand(
    name: 'documents:erasure:set-override',
    description: 'Set the erasure ledger override (restore recovery mode; the reason is a closed list)',
)]
final class SetErasureLedgerOverrideCommand extends Command
{
    public function __construct(
        private readonly ErasureOverrideSwitch $overrideSwitch,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(
            'reason',
            InputArgument::REQUIRED,
            'One of: ' . \implode(', ', \array_map(
                static fn (LedgerOverrideReason $reason): string => $reason->value,
                LedgerOverrideReason::cases(),
            )),
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('reason');
        $reason = \is_string($argument) ? LedgerOverrideReason::tryFrom($argument) : null;

        if (!$reason instanceof LedgerOverrideReason) {
            $io->error('The reason must be one of the closed list.');

            return Command::INVALID;
        }

        $recordRef = $this->overrideSwitch->activate($reason);

        $io->success('The erasure ledger override is set. Write this reference in the off-host operator log:');
        $io->writeln($recordRef);

        return Command::SUCCESS;
    }
}
