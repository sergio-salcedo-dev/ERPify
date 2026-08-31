<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Infrastructure\Cli\EraseActorAuditTrailCommand;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Console\Double\DrainedInputStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Whether the operator was asked, and what the command does with the answer — the half of this command that
 * decides, kept apart from the half that erases. The three refusals differ in mechanism and each has its own
 * case: the flag says the run is unattended, the question helper demotes the input while answering with its
 * default, and a stream a previous read exhausted never lets the helper raise at all.
 *
 * @internal
 */
#[CoversClass(EraseActorAuditTrailCommand::class)]
final class EraseActorAuditTrailCommandConfirmationTest extends TestCase
{
    private const string ACTOR_ID = '550e8400-e29b-41d4-a716-446655440000';

    public function testDecliningTheConfirmationLeavesTheTrailIntact(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);
        $tester->setInputs(['no']);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
    }

    /**
     * The operator was asked and never answered, so success would be a lie a compliance job cannot see
     * through: it reads `$?`, and a `0` from a trail nobody anonymised is indistinguishable from a `0` from
     * one that was.
     */
    public function testAnUnattendedRunWithoutForceRefusesInsteadOfReportingSuccess(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID], ['interactive' => false]);

        $this->assertSame(Command::INVALID, $exitCode);
        // Single tokens: the refusal renders as a SymfonyStyle error block, which word-wraps to the terminal
        // width, so any multi-word phrase can straddle a line break the assertion cannot see.
        $this->assertStringContainsString('Refusing', $tester->getDisplay());
        $this->assertStringContainsString('--force', $tester->getDisplay());
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
        $this->assertSame(0, $anonymiser->countForCalls, 'a run that will refuse never queries the trail');
    }

    /**
     * A separate path from --no-interaction: the input is still interactive when the question is put, and the
     * question helper answers it with the default rather than raising.
     *
     * **The exit code may not vary with the trail here either, and this is the shape where that is hard to
     * hold.** `UnattendedRunPolicy::cannotAnswer()` cannot see an empty stdin that nothing has read yet, so
     * the count is taken before the demotion is discovered; only a question put whatever the count says keeps
     * both rows of the provider on one code. Returning early over an empty trail answers `0` here against `2`
     * for an actor with rows — an existence oracle a caller reads from `$?` alone.
     */
    #[DataProvider('provideAConfirmationNobodyCanAnswerRefusesWhateverTheTrailHoldsCases')]
    public function testAConfirmationNobodyCanAnswerRefusesWhateverTheTrailHolds(int $matchCount): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser($matchCount);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);
        $tester->setInputs([]);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Refusing', $tester->getDisplay());
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
        // One, not zero — and the asymmetry with the refusal above is the point. The input WAS interactive
        // when the question was put, so the preview it feeds was legitimately taken; what refuses here is the
        // re-read afterwards. Copying the `countForCalls === 0` assertion into this test would assert the
        // command skipped a preview it was right to take.
        $this->assertSame(1, $anonymiser->countForCalls, 'a question that was put needs its magnitude first');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideAConfirmationNobodyCanAnswerRefusesWhateverTheTrailHoldsCases(): iterable
    {
        yield 'an actor with rows' => [5];
        yield 'an actor with none' => [0];
    }

    /**
     * An ORDERING pin, not a regression pin: this passes with or without the unattended refusal, because the
     * dry run short-circuits before the confirmation either way. What it fixes in place is that order — the
     * dry run is the one no-op the operator did express, so it keeps its exit code where a run that was never
     * asked does not, and it keeps its preview, which is the whole reason the option exists.
     */
    public function testAnUnattendedDryRunStaysSuccessful(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(
            ['actor-id' => self::ACTOR_ID, '--dry-run' => true],
            ['interactive' => false],
        );

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
    }

    /**
     * The precedence between the two flags, which nothing else pins. `--force` says "do not ask me"; it does
     * not say "erase". A run passing both asked for a preview and gets one — the only reading under which
     * `--dry-run` is safe to leave in a script that later gains `--force`.
     */
    public function testADryRunKeepsItsPreviewWhenForceIsPassedToo(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(
            ['actor-id' => self::ACTOR_ID, '--dry-run' => true, '--force' => true],
            ['interactive' => false],
        );

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Rows matched: 5', $tester->getDisplay());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
    }

    /**
     * The exit code of a refused run may not depend on what the trail holds. It used to: the count ran ahead
     * of the guards, so an unattended run without `--force` answered `2` for an actor with rows and `0` for
     * one without — an existence oracle over an actor id, readable by a caller who never sees stdout because
     * `--quiet`, `--silent`, a negative `SHELL_VERBOSITY` or a plain `>/dev/null` suppressed it.
     *
     * Asserting the display as well as the code is deliberate: an oracle that moved from `$?` into the
     * message would satisfy a code-only assertion.
     */
    #[DataProvider('provideAnUnattendedRunRefusesIdenticallyWhateverTheTrailHoldsCases')]
    public function testAnUnattendedRunRefusesIdenticallyWhateverTheTrailHolds(int $matchCount): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser($matchCount);
        $logger = new RecordingAuditLogger();
        $tester = $this->testerFor($anonymiser, $logger);

        $exitCode = $tester->execute(['actor-id' => self::ACTOR_ID], ['interactive' => false]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringNotContainsString('Rows matched', $tester->getDisplay());
        $this->assertSame(0, $anonymiser->countForCalls);
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideAnUnattendedRunRefusesIdenticallyWhateverTheTrailHoldsCases(): iterable
    {
        yield 'an actor with rows' => [5];
        yield 'an actor with none' => [0];
    }

    /**
     * The stdin case neither other guard reaches. `QuestionHelper::doReadInput()` loops
     * `while (!feof($inputStream))`, so a stream a previous read already exhausted never enters the loop and
     * never raises the `MissingInputException` the post-`confirm()` re-read depends on — the default is taken
     * as an operator's answer and the run exits `0` having erased nothing. Reachable through the console's
     * own single-alternative prompt, which drains a pipe whose last byte is not a newline.
     *
     * Driven through `Command::run()` rather than `CommandTester`, because the tester always mints a fresh
     * stream and there is no way to hand it a drained one.
     */
    public function testAConfirmationOnAnAlreadyDrainedStreamRefusesInsteadOfReportingSuccess(): void
    {
        $anonymiser = new RecordingAuditActorAnonymiser(5);
        $logger = new RecordingAuditLogger();
        $command = new EraseActorAuditTrailCommand($anonymiser, $logger);

        $input = new ArrayInput(['actor-id' => self::ACTOR_ID], $command->getDefinition());
        $input->setInteractive(true);
        $input->setStream(DrainedInputStream::open());

        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Refusing', $output->fetch());
        $this->assertSame(0, $anonymiser->countForCalls, 'a stream nobody can read is refused before the preview');
        $this->assertCount(0, $anonymiser->anonymisedActorIds);
        $this->assertCount(0, $logger->records);
    }

    private function testerFor(AuditActorAnonymiser $anonymiser, AuditLogger $logger): CommandTester
    {
        return new CommandTester(new EraseActorAuditTrailCommand($anonymiser, $logger));
    }
}
