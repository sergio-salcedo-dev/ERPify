<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Cli;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\CreateUser;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordPolicyCheck;
use Erpify\Organization\Membership\Application\GrantMembership;
use Erpify\Shared\Access\Domain\Role;
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
 * Bootstraps the installation's first administrator: creates the identity carrying ADMIN and, in the same
 * transaction, its membership in the already-provisioned organization — so a user never exists without a
 * membership, and the organization satisfies its "at least one active ADMIN" invariant from the start.
 * The role is written to the identity alone, which is where authorization is read from; the membership
 * carries the belonging and nothing else. There is no public sign-up; subsequent members arrive by
 * invitation. The plaintext password is hashed here in Infrastructure and never printed or logged; prefer
 * the hidden prompt over the visible argument.
 *
 * The coupling is the shape of a composition root, not a smell to refactor away. This command is the one
 * place that joins two bounded contexts — `CreateUser` in Identity and `GrantMembership` in Organization —
 * around a single transaction, precisely because no Application layer may know both. Lifting the body into
 * an Application use case would not reduce the coupling; it would move it inward and demand a cross-context
 * seam to hold it there.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsCommand(
    name: 'organization:administrator:create',
    description: "Create the installation's first administrator (ADMIN identity + organization membership)",
)]
final class CreateInitialAdministratorCommand extends Command
{
    public function __construct(
        private readonly CreateUser $createUser,
        private readonly GrantMembership $grantMembership,
        private readonly PasswordHasher $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly PasswordPolicyCheck $passwordPolicyCheck,
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

        // The policy is weighed before anything is built, hashed or persisted: this is input validation, and
        // it is the same policy the three HTTP surfaces enforce. The bootstrap account is the most privileged
        // one the installation will ever hold, so it is the last place that should admit a weaker credential.
        $violations = $this->passwordPolicyCheck->violationsFor($plainPassword);

        if ([] !== $violations) {
            $io->error($violations);

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

        // A closed/EOF stdin at the hidden prompt (Ctrl+D, or a non-interactive run with no input) makes the
        // question helper throw a console RuntimeException; treat that as "no password" so it flows to the
        // clean Command::INVALID below instead of surfacing a raw stack trace to the operator.
        try {
            $hidden = $io->askHidden('Password');
        } catch (RuntimeException) {
            return '';
        }

        return \is_string($hidden) ? $hidden : '';
    }

    private function createAndReport(
        SymfonyStyle $io,
        #[SensitiveParameter]
        string $email,
        #[SensitiveParameter]
        string $plainPassword,
    ): int {
        try {
            $hashedPassword = HashedPassword::fromHash($this->passwordHasher->hash($plainPassword));

            $this->entityManager->wrapInTransaction(function () use ($email, $hashedPassword): void {
                $user = $this->createUser->create($email, $hashedPassword, Role::ADMIN);
                $userId = $user->getId() ?? throw new RuntimeException('The created user has no id.');

                $this->grantMembership->grant($userId);
            });
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Could not create the administrator: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created administrator %s and their organization membership.', $email));

        return Command::SUCCESS;
    }
}
