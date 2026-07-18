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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Invites a new member: creates the `INVITED` identity, its membership and the `SENT` invitation atomically,
 * sends the email, and prints the raw accept token once (as the email delivers it) so an operator can hand it
 * over out of band. A thin adapter over {@see SendInvitation}; the management HTTP surface (deferred to the
 * member-lifecycle slice) will wrap the very same use case.
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
            $acceptToken = $this->sendInvitation->invite($email, ...$this->resolveRoles($input));
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Could not create the invitation: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Invitation sent to %s.', $email));
        $io->writeln($acceptToken);

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
