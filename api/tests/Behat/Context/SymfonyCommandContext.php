<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Behat\Step\When;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use InvalidArgumentException;
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
    private const string NO_COMMAND_RAN = 'No command has been executed yet';

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
     * @throws JsonException            when the body is not valid JSON
     * @throws InvalidArgumentException when the body is not a JSON object of "--name": value pairs
     */
    #[When('I run the :commandName command with options:')]
    public function iRunTheCommandWithOptions(string $commandName, PyStringNode $options): void
    {
        $this->execute($commandName, $this->decodeOptions($options));
    }

    /**
     * One string value per option/argument name. A two-column table cannot express a VALUE_NONE
     * flag (it would bind the empty string, never true), a multi-value option (duplicate rows
     * collapse last-wins), or a non-string value — use the JSON `with options:` body for those.
     */
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
        $this->guardCommandRan();

        self::assertStringContainsString(
            $needle,
            $this->lastOutput,
            \sprintf('Command output did not contain "%s". Output:%s%s', $needle, PHP_EOL, $this->lastOutput),
        );
    }

    /**
     * @throws JsonException when the output is not valid JSON
     */
    #[Then('the command output should be JSON with a :field field')]
    public function theCommandOutputShouldBeJsonWithField(string $field): void
    {
        $this->guardCommandRan();

        /** @var array<string, mixed> $decoded */
        $decoded = (array) \json_decode($this->lastOutput, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey(
            $field,
            $decoded,
            \sprintf('Command output was not JSON with a "%s" field. Output:%s%s', $field, PHP_EOL, $this->lastOutput),
        );
    }

    /**
     * @phpstan-assert int $this->lastExitCode
     */
    private function guardCommandRan(): void
    {
        self::assertNotNull($this->lastExitCode, self::NO_COMMAND_RAN);
    }

    private function exitCode(): int
    {
        $this->guardCommandRan();

        return $this->lastExitCode;
    }

    /**
     * @throws JsonException            when the body is not valid JSON
     * @throws InvalidArgumentException when the body is not a JSON object of named options
     *
     * @return array<string, mixed>
     */
    private function decodeOptions(PyStringNode $options): array
    {
        $decoded = \json_decode($options->getRaw(), true, 512, JSON_THROW_ON_ERROR);

        // A JSON array/scalar decodes to integer keys, which ApplicationTester would bind as surplus
        // positional arguments (an opaque "too many arguments"); require an object of named options.
        if (!\is_array($decoded) || (\array_is_list($decoded) && [] !== $decoded)) {
            throw new InvalidArgumentException(\sprintf(
                'Command options must be a JSON object of "--name": value pairs, got: %s',
                $options->getRaw(),
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function execute(string $commandName, array $parameters = []): void
    {
        // The real console hands every option/argument value to a command as a string, so a numeric
        // JSON value (5) is stringified to match — a command re-parsing its own input as a string
        // (e.g. a numeric --limit) then behaves as on the CLI. Booleans stay booleans so a
        // VALUE_NONE flag still reads true, not "1".
        $values = \array_map(
            static fn (mixed $value): mixed => \is_int($value) || \is_float($value) ? (string) $value : $value,
            $parameters,
        );

        $tester = new ApplicationTester($this->application());
        // Non-interactive: a generic "run any command" harness must never block on a prompt
        // (confirm/ask) waiting for stdin — take defaults / fail fast instead of hanging.
        $tester->run(['command' => $commandName, ...$values], ['interactive' => false]);

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
