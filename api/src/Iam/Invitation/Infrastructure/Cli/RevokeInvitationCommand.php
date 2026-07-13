<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Cli;

use Erpify\Iam\Invitation\Application\RevokeInvitation;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Revokes a live invitation by id: its delivered token stops working at once. A thin adapter over
 * {@see RevokeInvitation}.
 */
#[AsCommand(
    name: 'iam:invitation:revoke',
    description: 'Revoke a live invitation so its delivered link stops working',
)]
final class RevokeInvitationCommand extends Command
{
    public function __construct(private readonly RevokeInvitation $revokeInvitation)
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
            $this->revokeInvitation->revoke($invitationId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Could not revoke the invitation: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Invitation %s revoked.', $invitationId));

        return Command::SUCCESS;
    }
}
