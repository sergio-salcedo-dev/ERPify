<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Cli;

use Erpify\Backoffice\Identity\Application\CreateUser;
use Erpify\Backoffice\Identity\Domain\Enum\Role;
use Erpify\Backoffice\Identity\Domain\HashedPassword;
use Erpify\Backoffice\Identity\Infrastructure\Security\PasswordHasher;
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
 * Bootstraps a backoffice user from the CLI — the only way to mint the first user, since there is no public
 * sign-up (identity is backoffice-only). Hashing happens here in Infrastructure and the command never prints
 * or logs the plaintext. Prefer the hidden prompt (omit the password argument): a password passed as an
 * argument is visible in the process list and shell history. Credentials are never seeded through migrations.
 */
#[AsCommand(
    name: 'identity:user:create',
    description: 'Create a backoffice user (hashes the password and persists the identity)',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly CreateUser $createUser,
        private readonly PasswordHasher $passwordHasher,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The user email (identifier)')
            ->addArgument(
                'password',
                InputArgument::OPTIONAL,
                'Plaintext password; prefer omitting it (hidden prompt) — an argument is visible in the process list',
            )
            ->addOption(
                'role',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                \sprintf('Role(s) to grant; one of: %s', \implode(', ', $this->roleValues())),
                [],
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

        $roles = $this->parseRoles($input, $io);

        if (null === $roles) {
            return Command::INVALID;
        }

        $plainPassword = $this->resolvePassword($input, $io);

        if ('' === $plainPassword) {
            $io->error('The password must not be empty.');

            return Command::INVALID;
        }

        return $this->createAndReport($io, $email, $plainPassword, $roles);
    }

    /**
     * @return list<Role>|null null signals an unknown role value (a caller error, not a failure to persist)
     */
    private function parseRoles(InputInterface $input, SymfonyStyle $io): ?array
    {
        /** @var list<string> $rawRoles */
        $rawRoles = $input->getOption('role');
        $roles = [];

        foreach ($rawRoles as $rawRole) {
            $role = Role::tryFrom($rawRole);

            if (null === $role) {
                $io->error(\sprintf(
                    'Unknown role "%s". Valid roles: %s.',
                    $rawRole,
                    \implode(', ', $this->roleValues()),
                ));

                return null;
            }

            $roles[] = $role;
        }

        return $roles;
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

    /**
     * @param list<Role> $roles
     */
    private function createAndReport(SymfonyStyle $io, string $email, string $plainPassword, array $roles): int
    {
        try {
            $hashedPassword = HashedPassword::fromHash($this->passwordHasher->hash($plainPassword));
            $user = $this->createUser->create($email, $hashedPassword, ...$roles);
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Could not create the user: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created user %s.', $user->email()));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function roleValues(): array
    {
        return \array_map(static fn (Role $role): string => $role->value, Role::cases());
    }
}
