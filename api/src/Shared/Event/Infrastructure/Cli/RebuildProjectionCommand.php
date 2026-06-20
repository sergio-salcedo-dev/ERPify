<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Cli;

use Erpify\Shared\Event\Application\ProjectionRunner;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds a projection read model from `sequence` 0: reset the read model and the checkpoint,
 * replay the event store. Reproducibility is the proof — a rebuilt `bank_count` must equal
 * `COUNT(*)` of `bank`.
 */
#[AsCommand(
    name: 'event:projection:rebuild',
    description: 'Rebuild a projection read model from the event store',
)]
final class RebuildProjectionCommand extends Command
{
    public function __construct(private readonly ProjectionRunner $projectionRunner)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Projection name to rebuild')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Rebuild every registered projection')
            ->addUsage('bank_count')
            ->addUsage('--all')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command replays the event store into a projection read model:
                it resets the read model and the projection checkpoint to <comment>0</comment>, then
                applies every stored event in <comment>sequence</comment> order.

                Rebuild a single projection by name:

                  <info>php %command.full_name% bank_count</info>

                Rebuild every registered projection in one pass:

                  <info>php %command.full_name% --all</info>

                Inside the dev stack the console runs in the <comment>php</comment> container:

                  <info>make sf c='%command.name% bank_count'</info>

                Reproducibility is the contract: after a rebuild, the <comment>bank_count</comment> read model
                must equal <comment>COUNT(*)</comment> of the <comment>bank</comment> table.
                HELP)
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('all')) {
            $this->projectionRunner->rebuildAll();
            $io->success('All projections rebuilt from the event store.');

            return Command::SUCCESS;
        }

        $name = $input->getArgument('name');

        if (!\is_string($name) || '' === $name) {
            $io->error('Provide a projection name, or use --all.');

            return Command::INVALID;
        }

        $this->projectionRunner->rebuild($name);
        $io->success(\sprintf('Projection "%s" rebuilt from the event store.', $name));

        return Command::SUCCESS;
    }
}
