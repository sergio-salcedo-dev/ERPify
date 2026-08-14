<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Cli;

use Erpify\Iam\Session\Application\PruneRetiredSessions;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The operator's entry into the session retention sweep, alongside the daily tick that runs it unattended.
 * Both delegate to {@see PruneRetiredSessions}, so this command decides nothing about the windows — it exists
 * so a sweep can be run and its count read now, rather than waited for.
 *
 * One run is idempotent and a missed run only defers the cleanup; live sessions are never touched.
 */
#[AsCommand(
    name: 'iam:session:prune',
    description: 'Delete session rows past their retention window (retention sweep)',
)]
final class PruneRetiredSessionsCommand extends Command
{
    public function __construct(
        private readonly PruneRetiredSessions $pruneRetiredSessions,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deleted = $this->pruneRetiredSessions->prune();

        $io->success(\sprintf('Pruned %d retired session(s).', $deleted));

        return Command::SUCCESS;
    }
}
