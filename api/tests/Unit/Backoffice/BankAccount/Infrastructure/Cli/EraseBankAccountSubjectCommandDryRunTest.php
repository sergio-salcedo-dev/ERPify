<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Cli;

use Erpify\Backoffice\BankAccount\Application\EraseBankAccountSubject;
use Erpify\Backoffice\BankAccount\Application\Evidence\ReleaseBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Backoffice\BankAccount\Infrastructure\Cli\EraseBankAccountSubjectCommand;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence\FakeEvidenceDocuments;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence\InMemoryBankAccountEvidenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What a dry run tells the operator about the account's evidence before anything is attempted: each attached
 * document that is not erased, since each would refuse the erasure.
 *
 * @internal
 */
#[CoversClass(EraseBankAccountSubjectCommand::class)]
#[CoversClass(ReleaseBankAccountEvidence::class)]
final class EraseBankAccountSubjectCommandDryRunTest extends TestCase
{
    private const string ACCOUNT_ID = '0197f2b4-0000-7000-8000-000000000000';

    private const string LIVE = '0197f2b4-2222-7000-8000-000000000001';

    private const string ERASED = '0197f2b4-2222-7000-8000-000000000002';

    #[Test]
    public function aDryRunNamesEachAttachedDocumentThatIsNotErasedAndErasesNothing(): void
    {
        $evidence = new InMemoryBankAccountEvidenceRepository();
        $documents = new FakeEvidenceDocuments();
        $documents->file(self::LIVE, 'ready');
        $documents->file(self::ERASED, 'erased');

        foreach ([self::LIVE, self::ERASED] as $documentId) {
            $evidence->add(BankAccountEvidence::attach(
                Uuid::generate(),
                self::ACCOUNT_ID,
                $documentId,
                BankAccountEvidenceKind::OwnershipCertificate,
            ));
        }

        $tester = new CommandTester(new EraseBankAccountSubjectCommand(
            // The dry run must never reach the eraser: an unconstructed one fails on first use.
            (new ReflectionClass(EraseBankAccountSubject::class))->newInstanceWithoutConstructor(),
            new ReleaseBankAccountEvidence($evidence, $documents),
        ));

        $tester->execute(['bank-account-id' => self::ACCOUNT_ID, '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString(self::LIVE, $display);
        $this->assertStringNotContainsString(self::ERASED, $display);
        $this->assertStringContainsString('Dry run', $display);
        $this->assertSame([], $evidence->removed);
    }
}
