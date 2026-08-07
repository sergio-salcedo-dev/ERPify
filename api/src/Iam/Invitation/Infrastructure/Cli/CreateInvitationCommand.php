<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Cli;

use Erpify\Iam\Invitation\Application\SendInvitation;
use Erpify\Shared\Access\Domain\Role;
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
 * Invites a new member: creates the `INVITED` identity, its membership and the `SENT` invitation atomically,
 * and sends the email. A thin adapter over {@see SendInvitation}; the management HTTP surface (deferred to the
 * member-lifecycle slice) will wrap the very same use case.
 *
 * **The raw accept token is printed only when it is needed or asked for**, because stdout is not a private
 * channel: it survives in scrollback, in shell history and in the logs of whatever ran the command, and the
 * value is the whole credential. Printing it unconditionally on success made every invitation pay that cost
 * for the benefit of the rare one whose email never got out. `--show-token` buys the operator that copy
 * deliberately; a failed send prints it regardless, because then out-of-band hand-over is the only way the
 * invitee ever receives the link.
 */
#[AsCommand(
    name: 'iam:invitation:create',
    description: 'Invite a member (creates the identity + membership + invitation and emails the accept link)',
)]
final class CreateInvitationCommand extends Command
{
    public function __construct(private readonly SendInvitation $sendInvitation)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The invitee email (identifier)')
            ->addArgument(
                'roles',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Org-scoped roles (default: VIEWER)',
            )
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

        $email = $input->getArgument('email');

        if (!\is_string($email)) {
            $io->error('The email argument is required.');

            return Command::INVALID;
        }

        try {
            $issued = $this->sendInvitation->invite($email, ...$this->resolveRoles($input));
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Could not create the invitation: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Invitation created for %s.', $email));

        if (!$issued->mailerAccepted) {
            $io->warning(\sprintf(
                'The invitation email to %s could not be sent. The invitation is live; hand the accept token '
                . 'below over out of band, or re-run with iam:invitation:resend once mail works.',
                $email,
            ));
        }

        if (!$issued->mailerAccepted || true === $input->getOption('show-token')) {
            $io->writeln($issued->acceptToken);
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<Role>
     */
    private function resolveRoles(InputInterface $input): array
    {
        $roles = $input->getArgument('roles');

        if (!\is_array($roles) || [] === $roles) {
            return [Role::VIEWER];
        }

        return \array_values(\array_map(
            Role::from(...),
            \array_filter($roles, \is_string(...)),
        ));
    }
}
