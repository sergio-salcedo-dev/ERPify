<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Cli;

use Erpify\Documents\Document\Infrastructure\Storage\StorageBootProbe;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The boot guard of the document storage, run by the container entrypoint before the server or a worker starts:
 * a non-zero exit stops the container. See {@see StorageBootProbe}.
 */
#[AsCommand(
    name: 'documents:storage:probe',
    description: 'Prove the document storage can stage, promote and delete an object (boot guard)',
)]
final class ProbeDocumentStorageCommand extends Command
{
    public function __construct(
        private readonly StorageBootProbe $probe,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->probe->probe();
        } catch (Throwable $throwable) {
            $io->error('The document storage refused the boot probe: ' . $throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success('The document storage staged, promoted and deleted a probe object.');

        return Command::SUCCESS;
    }
}
