<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Cli;

use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Backoffice\BankAccount\Infrastructure\Cli\EraseBankAccountSubjectCommand;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Tests\Unit\Shared\Console\Double\DrainedInputStream;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Whether the operator was asked, and what the command does with the answer — the half of this command that
 * decides, kept apart from the half that erases, as its two sibling erasure commands already are. The three
 * refusals differ in mechanism and each has its own case: the flag says the run is unattended, the question
 * helper demotes the input while answering with its default, and a stream a previous read exhausted never
 * lets the helper raise at all.
 *
 * @internal
 */
#[CoversClass(EraseBankAccountSubjectCommand::class)]
final class EraseBankAccountSubjectCommandConfirmationTest extends TestCase
{
    private const string ACCOUNT_ID = '0197f2b4-0000-7000-8000-000000000000';

    #[Test]
    public function aDeclinedConfirmationAbortsWithoutErasing(): void
    {
        $tester = $this->tester($this->refusingEraser());
        $tester->setInputs(['no']);

        $tester->execute(['bank-account-id' => self::ACCOUNT_ID]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
    }

    /**
     * The operator was asked and never answered, so success would be a lie a compliance job cannot see
     * through: it reads `$?`, and a `0` from an erasure that shredded nothing is indistinguishable from a `0`
     * from one that destroyed the key.
     */
    #[Test]
    public function anUnattendedRunWithoutForceRefusesInsteadOfReportingSuccess(): void
    {
        $tester = $this->tester($this->refusingEraser());

        $tester->execute(['bank-account-id' => self::ACCOUNT_ID], ['interactive' => false]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        // Single tokens: the refusal renders as a SymfonyStyle error block, which word-wraps to the terminal
        // width, so any multi-word phrase can straddle a line break the assertion cannot see.
        $this->assertStringContainsString('Refusing', $tester->getDisplay());
        $this->assertStringContainsString('--force', $tester->getDisplay());
    }

    /**
     * A separate path from --no-interaction: the input is still interactive when the question is put, and the
     * question helper answers it with the default rather than raising.
     */
    #[Test]
    public function aConfirmationNobodyCanAnswerRefusesInsteadOfReportingSuccess(): void
    {
        $tester = $this->tester($this->refusingEraser());
        $tester->setInputs([]);

        $tester->execute(['bank-account-id' => self::ACCOUNT_ID]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('Refusing', $tester->getDisplay());
        $this->assertStringContainsString('--force', $tester->getDisplay());
    }

    /**
     * An ORDERING pin, not a regression pin: this passes with or without the unattended refusal, because the
     * dry run short-circuits before the confirmation either way. What it fixes in place is that order — the
     * dry run is the one no-op the operator did express, so it must keep its exit code where a run that was
     * never asked does not.
     */
    #[Test]
    public function anUnattendedDryRunStaysSuccessful(): void
    {
        $tester = $this->tester($this->refusingEraser());

        $tester->execute(
            ['bank-account-id' => self::ACCOUNT_ID, '--dry-run' => true],
            ['interactive' => false],
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
    }

    /**
     * The precedence between the two flags, which nothing else pins. `--force` says "do not ask me"; it does
     * not say "erase". A run passing both asked for a preview and gets one — the only reading under which
     * `--dry-run` is safe to leave in a script that later gains `--force`.
     */
    #[Test]
    public function aDryRunKeepsItsNoOpWhenForceIsPassedToo(): void
    {
        $tester = $this->tester($this->refusingEraser());

        $tester->execute(
            ['bank-account-id' => self::ACCOUNT_ID, '--dry-run' => true, '--force' => true],
            ['interactive' => false],
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
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
    #[Test]
    public function aConfirmationOnAnAlreadyDrainedStreamRefusesInsteadOfReportingSuccess(): void
    {
        $command = new EraseBankAccountSubjectCommand($this->refusingEraser());

        $input = new ArrayInput(['bank-account-id' => self::ACCOUNT_ID], $command->getDefinition());
        $input->setInteractive(true);
        $input->setStream(DrainedInputStream::open());

        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Refusing', $output->fetch());
    }

    private function tester(EraseBankAccountSubject $eraser): CommandTester
    {
        return new CommandTester(new EraseBankAccountSubjectCommand($eraser));
    }

    /**
     * An eraser whose two irreversible calls are refused outright, so a test asserting "nothing was erased"
     * fails on the mutation itself instead of on an exit code that happens to agree with it. A stubbed eraser
     * cannot do this job and reading it as if it could is the trap: stubs answer `findById(): null` and
     * `destroyScope(): false`, so the use case runs to completion and reports "nothing to erase" — a green
     * that says the subject had no record, never that the command declined to look.
     */
    private function refusingEraser(): EraseBankAccountSubject
    {
        $repository = $this->createMock(BankAccountRepository::class);
        $repository->expects($this->never())->method('remove');

        $encryptor = $this->createMock(EnvelopeEncryptor::class);
        $encryptor->expects($this->never())->method('destroyScope');

        return new EraseBankAccountSubject(
            $repository,
            $encryptor,
            $this->createStub(AuditLogger::class),
            new ImmediateTransactionManager(),
        );
    }
}
