<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Console;

use Erpify\Shared\Console\Infrastructure\UnattendedRunPolicy;
use Erpify\Tests\Unit\Shared\Console\Double\RecordingPolicyProbeCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The four spellings of a run that cannot answer its own confirmation, measured rather than asserted.
 *
 * **Only one of the four is reachable from `CommandTester`, and that is why this class exists.** The three
 * erasure commands' own tests all reach non-interactivity through `['interactive' => false]` — the
 * `--no-interaction` mechanism, and only it. The other three (`--quiet`, `--silent`, and a `SHELL_VERBOSITY`
 * inherited from a parent process) are folded into the same flag by `Application::configureIO()`, which
 * `CommandTester` never runs: it goes straight to `Command::run()`. The equivalence stated at
 * {@see UnattendedRunPolicy::cannotAnswer()} and in three other documents therefore had no falsifier at all,
 * and it is a property of the installed console rather than of anything in this repository — the kind of
 * claim a dependency bump can retire in silence.
 *
 * **Every row is a one-flag delta from the answerable one, and that is what keeps them from passing
 * vacuously.** The suite runs under `SHELL_VERBOSITY=-1` (`api/tools/phpunit/phpunit.dist.xml`), which
 * `configureIO()` reads as its default and which demotes a run before any flag is examined — so a row
 * spelling `--quiet` over that baseline would be unanswerable with or without the flag, proving nothing.
 * `-v` resolves ahead of the environment in the same `match`, so it pins the baseline to a positive
 * verbosity per case and each row then differs from the answerable one by exactly the spelling it is named
 * for. The one row carrying no `-v` is the inherited-verbosity case itself, whose subject IS that default:
 * it fails, rather than going quiet, if the runner ever stops setting it.
 *
 * **The isolation is not decoration.** `configureIO()` writes its resolved verbosity back into `putenv`,
 * `$_ENV` and `$_SERVER`, so in one process the rows would decide each other's baseline — the inherited case
 * would read whatever the row before it happened to leave, reporting green off a residue rather than off the
 * runner's configuration. Isolating is also what keeps this class from deciding the verbosity of every test
 * that follows it.
 *
 * @internal
 */
#[CoversClass(UnattendedRunPolicy::class)]
#[RunTestsInSeparateProcesses]
final class UnattendedRunPolicyTest extends TestCase
{
    /**
     * @param array<string, bool> $parameters the command line, beyond the command name
     */
    #[DataProvider('provideEverySpellingOfAnUnattendedRunCases')]
    public function testEverySpellingOfAnUnattendedRun(array $parameters, bool $cannotAnswer): void
    {
        $command = new RecordingPolicyProbeCommand();
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->addCommand($command);

        $input = new ArrayInput(['command' => RecordingPolicyProbeCommand::NAME] + $parameters);
        $stream = \fopen('php://memory', 'r+');

        if (false === $stream) {
            $this->fail('this case needs a stream nothing has read, and one could not be opened');
        }

        // Answerable-looking by construction: `feof()` is false on a stream nothing has read, so the stream
        // half of the predicate never fires and each row measures the flag half alone. Left unset, the input
        // falls back to the process's own STDIN, whose position is a property of how the suite was invoked.
        $input->setStream($stream);

        $exitCode = $application->run($input, new NullOutput());

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNotNull($command->cannotAnswer, 'the probe never ran, so its verdict proves nothing');
        $this->assertSame($cannotAnswer, $command->cannotAnswer);
    }

    /**
     * @return iterable<string, array{array<string, bool>, bool}>
     */
    public static function provideEverySpellingOfAnUnattendedRunCases(): iterable
    {
        yield 'a verbosity the console leaves interactive' => [['-v' => true], false];
        yield '--no-interaction' => [['-v' => true, '--no-interaction' => true], true];
        yield '--quiet' => [['-v' => true, '--quiet' => true], true];
        yield '--silent' => [['-v' => true, '--silent' => true], true];
        yield 'a negative SHELL_VERBOSITY inherited from the runner' => [[], true];
    }
}
