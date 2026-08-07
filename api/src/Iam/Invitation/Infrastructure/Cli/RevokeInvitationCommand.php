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
 * Revokes a live invitation by id: its delivered token stops working at once AND the identity it provisioned is
 * withdrawn to `REVOKED`, in the same transaction. That second write is terminal — a withdrawn identity can
 * never activate — so this is not a reversible cleanup of a delivery record.
 *
 * The withdrawn row keeps the address, and `identity_user.email` is unique, so re-inviting it is refused while
 * that row stands. Recovering the address is possible but is not an undo: it takes erasing the identity, which
 * is itself refused while the row carries `ADMIN`, so the sequence is demote, then erase, then invite again.
 * A thin adapter over {@see RevokeInvitation}.
 */
#[AsCommand(
    name: 'iam:invitation:revoke',
    description: 'Revoke a live invitation: its link stops working and the invited identity is withdrawn',
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
