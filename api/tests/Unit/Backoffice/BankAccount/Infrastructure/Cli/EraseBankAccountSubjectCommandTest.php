<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Cli;

use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Backoffice\BankAccount\Infrastructure\Cli\EraseBankAccountSubjectCommand;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(EraseBankAccountSubjectCommand::class)]
final class EraseBankAccountSubjectCommandTest extends TestCase
{
    private const string ACCOUNT_ID = '0197f2b4-0000-7000-8000-000000000000';

    private const string BANK_ID = '0197f2b4-1111-7000-8000-000000000001';

    #[Test]
    public function itRejectsANonUuidArgument(): void
    {
        $tester = $this->tester($this->inertEraser());

        $tester->execute(['bank-account-id' => 'not-a-uuid']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function aDryRunMutatesNothing(): void
    {
        $tester = $this->tester($this->refusingEraser());

        $tester->execute(['bank-account-id' => self::ACCOUNT_ID, '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
    }

    #[Test]
    public function aDeclinedConfirmationAbortsWithoutErasing(): void
    {
        $tester = $this->tester($this->refusingEraser());
        $tester->setInputs(['no']);

        $tester->execute(['bank-account-id' => self::ACCOUNT_ID]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
    }

    #[Test]
    public function itErasesTheSubjectOnConfirmation(): void
    {
        $repository = $this->createStub(BankAccountRepository::class);
        $repository->method('findById')->willReturn(
            BankAccount::create(self::ACCOUNT_ID, self::BANK_ID, 'Juan Pérez', 'ES9121000418450200051332'),
        );
        $encryptor = $this->createStub(EnvelopeEncryptor::class);
        $encryptor->method('destroyScope')->willReturn(true);

        $tester = $this->tester($this->eraser($repository, $encryptor));
        $tester->execute(['bank-account-id' => self::ACCOUNT_ID, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Erased subject', $tester->getDisplay());
    }

    #[Test]
    public function itReportsNothingToEraseForAnAlreadyErasedSubject(): void
    {
        // The inert eraser finds no live record and no key, so a forced run is a no-op re-run.
        $tester = $this->tester($this->inertEraser());

        $tester->execute(['bank-account-id' => self::ACCOUNT_ID, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing to erase', $tester->getDisplay());
    }

    #[Test]
    public function itReportsFailureWhenTheErasureThrows(): void
    {
        $encryptor = $this->createStub(EnvelopeEncryptor::class);
        $encryptor->method('destroyScope')->willThrowException(new RuntimeException('crypto backend down'));

        $tester = $this->tester($this->eraser($this->createStub(BankAccountRepository::class), $encryptor));
        $tester->execute(['bank-account-id' => self::ACCOUNT_ID, '--force' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Erasure failed', $tester->getDisplay());
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
        $input->setStream($this->drainedStream());

        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Refusing', $output->fetch());
    }

    private function tester(EraseBankAccountSubject $eraser): CommandTester
    {
        return new CommandTester(new EraseBankAccountSubjectCommand($eraser));
    }

    private function inertEraser(): EraseBankAccountSubject
    {
        return $this->eraser(
            $this->createStub(BankAccountRepository::class),
            $this->createStub(EnvelopeEncryptor::class),
        );
    }

    /**
     * An eraser whose two irreversible calls are refused outright, so a test asserting "nothing was erased"
     * fails on the mutation itself instead of on an exit code that happens to agree with it.
     *
     * {@see inertEraser()} cannot do this job and reading it as if it could is the trap: its stubs answer
     * `findById(): null` and `destroyScope(): false`, so the use case runs to completion and reports
     * "nothing to erase" — a green that says the subject had no record, never that the command declined to
     * look. Refusing the calls is what separates the two.
     */
    private function refusingEraser(): EraseBankAccountSubject
    {
        $repository = $this->createMock(BankAccountRepository::class);
        $repository->expects($this->never())->method('remove');

        $encryptor = $this->createMock(EnvelopeEncryptor::class);
        $encryptor->expects($this->never())->method('destroyScope');

        return $this->eraser($repository, $encryptor);
    }

    private function eraser(BankAccountRepository $repository, EnvelopeEncryptor $encryptor): EraseBankAccountSubject
    {
        return new EraseBankAccountSubject(
            $repository,
            $encryptor,
            $this->createStub(AuditLogger::class),
            new ImmediateTransactionManager(),
        );
    }

    /**
     * @return resource a stream a read has already taken to EOF, so `feof()` is true before the question
     */
    private function drainedStream()
    {
        $stream = \fopen('php://memory', 'r+');
        \assert(\is_resource($stream));
        \fwrite($stream, 'y');
        \rewind($stream);
        \fread($stream, 1);
        \fread($stream, 1);
        \assert(\feof($stream));

        return $stream;
    }
}
