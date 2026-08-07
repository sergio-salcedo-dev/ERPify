<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Cli;

use Erpify\Iam\Invitation\Application\ResendInvitation;
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
 * Re-sends a live invitation with a fresh token, invalidating the previous one. A thin adapter over
 * {@see ResendInvitation}.
 *
 * **The raw accept token is printed only when it is needed or asked for**, on the same reasoning as
 * {@see CreateInvitationCommand}: stdout keeps the whole credential in scrollback, shell history and the logs
 * of whatever ran the command. A failed send prints it regardless — a resend whose email did not get out
 * leaves out-of-band hand-over as the only remaining route, and the previous link is already dead.
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
        $this
            ->addArgument('invitationId', InputArgument::REQUIRED, 'The invitation id (UUID)')
            ->addOption(
                'show-token',
                null,
                InputOption::VALUE_NONE,
                'Print the raw accept token even when the invitation email got out',
            )
        ;
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
            $issued = $this->resendInvitation->resend($invitationId);
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Could not resend the invitation: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Invitation %s re-tokenised.', $invitationId));

        if (!$issued->mailerAccepted) {
            $io->warning(
                'The invitation email could not be sent, and the previous link is already dead. Hand the '
                . 'accept token below over out of band.',
            );
        }

        if (!$issued->mailerAccepted || true === $input->getOption('show-token')) {
            $io->writeln($issued->acceptToken);
        }

        return Command::SUCCESS;
    }
}
