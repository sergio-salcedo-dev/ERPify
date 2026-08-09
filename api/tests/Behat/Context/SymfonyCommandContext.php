<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Then;
use Behat\Step\When;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Execution\LastRun;
use InvalidArgumentException;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Executes and asserts Symfony console commands in-process against the booted test kernel, so a
 * scenario can cover an `#[AsCommand]` end to end (exit code + captured output).
 *
 * It owns how a command is *started*; what it left behind goes into {@see LastRun}, and asserting on
 * that belongs to {@see RunOutcomeContext}. The split is what let one vocabulary replace two: Behat
 * resolves a step by pattern, so two contexts can never both register a phrase — sharing the words
 * means sharing the result, not the step definitions.
 *
 * Booting the kernel to run a command is the established pattern in this suite ({@see FixturesContext}
 * loads Doctrine fixtures via a console {@see Application}); the "drive over HTTP" rule governs HTTP
 * scenarios, not console tests.
 */
final class SymfonyCommandContext extends AbstractContext
{
    public function __construct(
        private readonly LastRun $lastRun,
        private readonly KernelInterface $kernel,
    ) {
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

    /**
     * Kept registered and refusing, so a scenario reaching for one is handed the canonical phrasing
     * rather than "step undefined", and nobody re-adds it believing it is missing.
     *
     * None of them is wrong about its own subject. They are the second vocabulary for assertions
     * {@see MessengerConsumerContext} had already claimed generic words for, so a reader has to know
     * which mechanism a scenario uses before picking between them. What replaces them names neither
     * mechanism, because a Messenger worker run is not a command either.
     */
    #[Then('the last command should succeed')]
    public function theLastCommandShouldSucceed(): void
    {
        $this->refuseSupersededPhrase('the last run should succeed');
    }

    /**
     * Superseded — refuses. See {@see theLastCommandShouldSucceed()}.
     */
    #[Then('the last command should fail')]
    public function theLastCommandShouldFail(): void
    {
        $this->refuseSupersededPhrase('the last run should fail');
    }

    /**
     * Superseded — refuses. See {@see theLastCommandShouldSucceed()}.
     */
    #[Then('the command output should contain :needle')]
    public function theCommandOutputShouldContain(string $needle): void
    {
        $this->refuseSupersededPhrase(\sprintf('the last run output should contain "%s"', $needle));
    }

    /**
     * Superseded — refuses. See {@see theLastCommandShouldSucceed()}.
     */
    #[Then('the command output should not contain :needle')]
    public function theCommandOutputShouldNotContain(string $needle): void
    {
        $this->refuseSupersededPhrase(\sprintf('the last run output should not contain "%s"', $needle));
    }

    /**
     * Superseded — refuses. See {@see theLastCommandShouldSucceed()}.
     */
    #[Then('the command output should be JSON with a :field field')]
    public function theCommandOutputShouldBeJsonWithField(string $field): void
    {
        $this->refuseSupersededPhrase(\sprintf('the last run output should be JSON with a "%s" field', $field));
    }

    private function refuseSupersededPhrase(string $canonical): never
    {
        throw new InvalidArgumentException(\sprintf(
            'This phrasing was one of two vocabularies for the same assertion, split only by which '
            . 'context reached the generic words first. Use: %s',
            $canonical,
        ));
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

        $this->lastRun->record($tester->getStatusCode(), $tester->getDisplay());
    }

    private function application(): Application
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        // Catch exceptions so a throwing command surfaces as a non-zero exit code plus rendered
        // output (what "the last run should fail" asserts) instead of aborting the scenario.
        $application->setCatchExceptions(true);

        return $application;
    }
}
