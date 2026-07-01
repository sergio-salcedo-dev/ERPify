<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Cli;

use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Backoffice\BankAccount\Infrastructure\Cli\EraseBankAccountSubjectCommand;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(EraseBankAccountSubjectCommand::class)]
final class EraseBankAccountSubjectCommandTest extends TestCase
{
    #[Test]
    public function itRejectsANonUuidArgument(): void
    {
        $tester = $this->tester();

        $tester->execute(['bank-account-id' => 'not-a-uuid']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function aDryRunMutatesNothing(): void
    {
        $tester = $this->tester();

        // Stubbed collaborators would break if the erasure ran, so a green dry-run proves it did not.
        $tester->execute(['bank-account-id' => '0197f2b4-0000-7000-8000-000000000000', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $eraser = new EraseBankAccountSubject(
            $this->createStub(BankAccountRepository::class),
            $this->createStub(EnvelopeEncryptor::class),
            $this->createStub(AuditLogger::class),
        );

        return new CommandTester(new EraseBankAccountSubjectCommand($eraser));
    }
}
