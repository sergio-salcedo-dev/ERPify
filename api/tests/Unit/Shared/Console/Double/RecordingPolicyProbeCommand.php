<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Console\Double;

use Erpify\Shared\Console\Infrastructure\UnattendedRunPolicy;
use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reads {@see UnattendedRunPolicy::cannotAnswer()} from inside a real `Application` run, which is the only
 * place three of the four spellings of an unattended run exist at all: `--quiet`, `--silent` and an inherited
 * `SHELL_VERBOSITY` are folded into the interactive flag by `Application::configureIO()`, and `CommandTester`
 * never reaches it — it calls `Command::run()` directly.
 *
 * The name is set in the constructor rather than declared with `#[AsCommand]`, so nothing that sweeps the
 * tree for commands has to decide whether a test double is one.
 *
 * @internal
 */
final class RecordingPolicyProbeCommand extends Command
{
    public const string NAME = 'test:unattended-run-probe';

    /** Null until the command has run, so a test cannot mistake "never executed" for "could answer". */
    public ?bool $cannotAnswer = null;

    public function __construct()
    {
        parent::__construct(self::NAME);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cannotAnswer = UnattendedRunPolicy::cannotAnswer($input);

        // Written as well as recorded, so a case wanting to read the verdict off the console rather than off
        // the probe can hand this an output it captures.
        $output->writeln($this->cannotAnswer ? 'unanswerable' : 'answerable');

        return Command::SUCCESS;
    }
}
