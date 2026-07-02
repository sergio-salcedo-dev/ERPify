<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Behat\Step\When;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Executes and asserts Symfony console commands in-process against the booted test kernel, so a
 * scenario can cover an `#[AsCommand]` end to end (exit code + captured output).
 *
 * The step vocabulary ("I run the ... command", "the last command should succeed") is deliberately
 * distinct from {@see MessengerConsumerContext}: its "the command should succeed" / "the output should
 * contain" phrases are wired to a Messenger {@see \Symfony\Component\Messenger\Worker}, not the console
 * {@see Application}, so reusing them here would redefine those patterns and break the whole suite.
 *
 * Booting the kernel to run a command is the established pattern in this suite ({@see FixturesContext}
 * loads Doctrine fixtures via a console {@see Application}); the "drive over HTTP" rule governs HTTP
 * scenarios, not console tests.
 */
final class SymfonyCommandContext extends AbstractContext
{
    private ?int $lastExitCode = null;

    private string $lastOutput = '';

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    #[BeforeScenario]
    public function reset(): void
    {
        $this->lastExitCode = null;
        $this->lastOutput = '';
    }

    #[When('I run the :commandName command')]
    public function iRunTheCommand(string $commandName): void
    {
        $this->execute($commandName);
    }

    /**
     * @throws JsonException
     */
    #[When('I run the :commandName command with options:')]
    public function iRunTheCommandWithOptions(string $commandName, PyStringNode $options): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = (array) \json_decode($options->getRaw(), true, 512, JSON_THROW_ON_ERROR);

        $this->execute($commandName, $decoded);
    }

    #[When('I run the :commandName command with parameters:')]
    public function iRunTheCommandWithParameters(string $commandName, TableNode $parameters): void
    {
        $this->execute($commandName, $parameters->getRowsHash());
    }

    #[Then('the last command should succeed')]
    public function theLastCommandShouldSucceed(): void
    {
        $exitCode = $this->exitCode();

        self::assertSame(
            Command::SUCCESS,
            $exitCode,
            \sprintf('Command failed (code %d). Output:%s%s', $exitCode, PHP_EOL, $this->lastOutput),
        );
    }

    #[Then('the last command should fail')]
    public function theLastCommandShouldFail(): void
    {
        self::assertNotSame(
            Command::SUCCESS,
            $this->exitCode(),
            \sprintf('Command unexpectedly succeeded. Output:%s%s', PHP_EOL, $this->lastOutput),
        );
    }

    #[Then('the command output should contain :needle')]
    public function theCommandOutputShouldContain(string $needle): void
    {
        self::assertStringContainsString(
            $needle,
            $this->lastOutput,
            \sprintf('Command output did not contain "%s". Output:%s%s', $needle, PHP_EOL, $this->lastOutput),
        );
    }

    #[Then('the command output should not contain :needle')]
    public function theCommandOutputShouldNotContain(string $needle): void
    {
        self::assertStringNotContainsString(
            $needle,
            $this->lastOutput,
            \sprintf('Command output unexpectedly contained "%s". Output:%s%s', $needle, PHP_EOL, $this->lastOutput),
        );
    }

    private function exitCode(): int
    {
        self::assertNotNull($this->lastExitCode, 'No command has been executed yet');

        return $this->lastExitCode;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function execute(string $commandName, array $parameters = []): void
    {
        $tester = new ApplicationTester($this->application());
        $tester->run(['command' => $commandName, ...$parameters]);

        $this->lastExitCode = $tester->getStatusCode();
        $this->lastOutput = $tester->getDisplay();
    }

    private function application(): Application
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        // Catch exceptions so a throwing command surfaces as a non-zero exit code plus rendered
        // output (what "the last command should fail" asserts) instead of aborting the scenario.
        $application->setCatchExceptions(true);

        return $application;
    }
}
