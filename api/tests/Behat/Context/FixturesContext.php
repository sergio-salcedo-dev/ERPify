<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class FixturesContext implements Context
{
    public function __construct(
        private KernelInterface $kernel,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Behat\Hook\BeforeScenario]
    public function reloadFixturesBeforeScenario(BeforeScenarioScope $scope): void
    {
        $this->entityManager->clear();

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'hautelook:fixtures:load',
                '--no-interaction' => true,
                '--purge-with-truncate' => true,
                '--quiet' => true,
            ]),
            new NullOutput(),
        );

        if (0 !== $exitCode) {
            throw new RuntimeException(\sprintf(
                'Failed to reload fixtures before scenario "%s" (exit %d).',
                $scope->getScenario()->getTitle() ?? 'unknown',
                $exitCode,
            ));
        }
    }
}
