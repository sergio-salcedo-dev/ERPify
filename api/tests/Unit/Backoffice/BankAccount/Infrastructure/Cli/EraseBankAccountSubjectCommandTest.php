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
        // An inert eraser would break if the erasure ran, so a green dry-run proves it did not.
        $tester = $this->tester($this->inertEraser());

        $tester->execute(['bank-account-id' => self::ACCOUNT_ID, '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
    }

    #[Test]
    public function aDeclinedConfirmationAbortsWithoutErasing(): void
    {
        $tester = $this->tester($this->inertEraser());
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

    private function eraser(BankAccountRepository $repository, EnvelopeEncryptor $encryptor): EraseBankAccountSubject
    {
        return new EraseBankAccountSubject(
            $repository,
            $encryptor,
            $this->createStub(AuditLogger::class),
            new ImmediateTransactionManager(),
        );
    }
}
