<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Cli;

use Erpify\Iam\Invitation\Application\ResendInvitation;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Re-sends a live invitation with a fresh token, invalidating the previous one, and prints the new accept token
 * once. A thin adapter over {@see ResendInvitation}.
 */
#[AsCommand(
    name: 'iam:invitation:resend',
    description: 'Re-send a live invitation with a fresh token (invalidates the previous link)',
)]
final class ResendInvitationCommand extends Command
{
    public function __construct(private readonly ResendInvitation $resendInvitation)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('invitationId', InputArgument::REQUIRED, 'The invitation id (UUID)');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $invitationId = $input->getArgument('invitationId');

        if (!\is_string($invitationId)) {
            $io->error('The invitation id argument is required.');

            return Command::INVALID;
        }

        try {
            $acceptToken = $this->resendInvitation->resend($invitationId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Could not resend the invitation: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Invitation %s re-sent.', $invitationId));
        $io->writeln($acceptToken);

        return Command::SUCCESS;
    }
}
