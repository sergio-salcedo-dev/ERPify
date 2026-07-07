<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\CreateUser;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Organization\Membership\Application\GrantMembership;
use Override;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Bootstraps the installation's first administrator: creates the identity and, in the same transaction,
 * its ADMIN membership in the already-provisioned organization — so a user never exists without a
 * membership, and the organization satisfies its "at least one active ADMIN" invariant from the start.
 * There is no public sign-up; subsequent members arrive by invitation. The plaintext password is hashed
 * here in Infrastructure and never printed or logged; prefer the hidden prompt over the visible argument.
 *
 * Roles are written to both the identity and the membership: the membership is the authoritative,
 * org-scoped home for roles, while the identity's role list stays the operative source the session firewall
 * reads today (the auth path is re-pointed at the membership in a later story, not here).
 */
#[AsCommand(
    name: 'organization:administrator:create',
    description: "Create the installation's first administrator (identity + ADMIN membership)",
)]
final class CreateInitialAdministratorCommand extends Command
{
    public function __construct(
        private readonly CreateUser $createUser,
        private readonly GrantMembership $grantMembership,
        private readonly PasswordHasher $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The administrator email (identifier)')
            ->addArgument(
                'password',
                InputArgument::OPTIONAL,
                'Plaintext password; prefer omitting it (hidden prompt) — an argument is visible in the process list',
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

        $plainPassword = $this->resolvePassword($input, $io);

        if ('' === $plainPassword) {
            $io->error('The password must not be empty.');

            return Command::INVALID;
        }

        return $this->createAndReport($io, $email, $plainPassword);
    }

    private function resolvePassword(InputInterface $input, SymfonyStyle $io): string
    {
        $password = $input->getArgument('password');

        if (\is_string($password) && '' !== $password) {
            return $password;
        }

        $hidden = $io->askHidden('Password');

        return \is_string($hidden) ? $hidden : '';
    }

    private function createAndReport(
        SymfonyStyle $io,
        string $email,
        #[SensitiveParameter]
        string $plainPassword,
    ): int {
        try {
            $hashedPassword = HashedPassword::fromHash($this->passwordHasher->hash($plainPassword));

            $this->entityManager->wrapInTransaction(function () use ($email, $hashedPassword): void {
                $user = $this->createUser->create($email, $hashedPassword, Role::ADMIN);
                $userId = $user->getId() ?? throw new RuntimeException('The created user has no id.');

                $this->grantMembership->grant($userId, Role::ADMIN);
            });
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Could not create the administrator: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created administrator %s with an ADMIN membership.', $email));

        return Command::SUCCESS;
    }
}
