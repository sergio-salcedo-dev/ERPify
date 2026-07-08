<?php

declare(strict_types=1);

namespace Erpify\Organization\Organization\Infrastructure\Cli;

use Erpify\Organization\Organization\Application\ProvisionOrganization;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Bootstraps the installation's single organization from the CLI. Run once at install time, before creating
 * the first administrator ({@see \Erpify\Iam\Identity\Infrastructure\Cli\CreateInitialAdministratorCommand}).
 * A second invocation is rejected — there is one organization per installation today.
 */
#[AsCommand(
    name: 'organization:provision',
    description: 'Provision the installation organization (one per installation)',
)]
final class ProvisionOrganizationCommand extends Command
{
    public function __construct(private readonly ProvisionOrganization $provisionOrganization)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The organization display name');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');

        if (!\is_string($name) || '' === \trim($name)) {
            $io->error('The name argument is required.');

            return Command::INVALID;
        }

        try {
            $organization = $this->provisionOrganization->provision($name);
        } catch (Throwable $throwable) {
            $io->error(\sprintf('Could not provision the organization: %s', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Provisioned organization %s.', $organization->name()));

        return Command::SUCCESS;
    }
}
