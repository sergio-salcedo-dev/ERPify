<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Application\Command\AttachBankAccountEvidenceCommand;
use Erpify\Backoffice\BankAccount\Application\Evidence\AttachBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Application\Evidence\DescribeEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceDocumentErased;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceDocumentNotFound;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceRetentionMismatch;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\InMemoryBankAccountRepository;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the use case, its doubles and one exception per refusal
 */
#[CoversClass(AttachBankAccountEvidence::class)]
#[CoversClass(DescribeEvidence::class)]
final class AttachBankAccountEvidenceTest extends TestCase
{
    private const string DOCUMENT_ID = '0197f2b4-2222-7000-8000-000000000001';

    private InMemoryBankAccountEvidenceRepository $evidence;

    private FakeEvidenceDocuments $documents;

    private ImmediateTransactionManager $transactions;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryBankAccountEvidenceRepository();
        $this->documents = new FakeEvidenceDocuments();
        $this->transactions = new ImmediateTransactionManager();
    }

    #[Test]
    public function aDocumentFiledUnderItsKindsTermsIsAttachedInsideOneTransaction(): void
    {
        $this->documents->file(self::DOCUMENT_ID);

        $listed = $this->attach()->attach(BankAccountMother::DEFAULT_ID, $this->command());

        $this->assertCount(1, $this->evidence->links);
        $link = $this->evidence->links[0];
        $this->assertSame(BankAccountMother::DEFAULT_ID, $link->bankAccountId());
        $this->assertSame(self::DOCUMENT_ID, $link->documentId());
        $this->assertSame($link->id(), $listed->id);
        $this->assertSame(self::DOCUMENT_ID . '.pdf', $listed->name);
        $this->assertSame('received', $listed->state);
        $this->assertSame(1, $this->transactions->committed);
    }

    #[Test]
    public function anUpperCasedDocumentIdIsTheSameDocument(): void
    {
        $this->documents->file(self::DOCUMENT_ID);

        $this->attach()->attach(
            \strtoupper(BankAccountMother::DEFAULT_ID),
            $this->command(\strtoupper(self::DOCUMENT_ID)),
        );

        $this->assertCount(1, $this->evidence->links);
        $this->assertSame(self::DOCUMENT_ID, $this->evidence->links[0]->documentId());
        $this->assertSame(BankAccountMother::DEFAULT_ID, $this->evidence->links[0]->bankAccountId());
    }

    #[Test]
    public function anUnknownAccountIsNotFoundAndNothingIsWritten(): void
    {
        $this->documents->file(self::DOCUMENT_ID);

        $this->assertRefusedWith(
            BankAccountNotFoundException::class,
            fn () => $this->attach(withAccount: false)->attach(BankAccountMother::DEFAULT_ID, $this->command()),
        );
    }

    #[Test]
    public function anUnknownDocumentIsRefusedAndNothingIsWritten(): void
    {
        $this->assertRefusedWith(
            BankAccountEvidenceDocumentNotFound::class,
            fn () => $this->attach()->attach(BankAccountMother::DEFAULT_ID, $this->command()),
        );
    }

    #[Test]
    public function anErasedDocumentIsRefusedAndNothingIsWritten(): void
    {
        $this->documents->file(self::DOCUMENT_ID, state: 'erased');

        $this->assertRefusedWith(
            BankAccountEvidenceDocumentErased::class,
            fn () => $this->attach()->attach(BankAccountMother::DEFAULT_ID, $this->command()),
        );
    }

    /**
     * @param array{?string, ?string, ?DateTimeImmutable} $terms
     */
    #[Test]
    #[DataProvider('provideADocumentFiledUnderOtherTermsIsRefusedCases')]
    public function aDocumentFiledUnderOtherTermsIsRefused(array $terms): void
    {
        $this->documents->file(self::DOCUMENT_ID, 'ready', ...$terms);

        $this->assertRefusedWith(
            BankAccountEvidenceRetentionMismatch::class,
            fn () => $this->attach()->attach(BankAccountMother::DEFAULT_ID, $this->command()),
        );
    }

    /**
     * @return iterable<string, array{array{?string, ?string, ?DateTimeImmutable}}>
     */
    public static function provideADocumentFiledUnderOtherTermsIsRefusedCases(): iterable
    {
        yield 'another retention reason' => [['tax-and-accounting', 'legitimate-interests', null]];
        yield 'another legal basis' => [['contract-execution', 'contract', null]];
        yield 'a review date the kind does not carry' => [
            ['contract-execution', 'legitimate-interests', new DateTimeImmutable('2031-01-01T00:00:00+00:00')],
        ];
        yield 'no terms at all' => [[null, null, null]];
    }

    #[Test]
    public function aDocumentTheAccountAlreadyHoldsIsAConflict(): void
    {
        $this->documents->file(self::DOCUMENT_ID);
        $this->attach()->attach(BankAccountMother::DEFAULT_ID, $this->command());

        $this->expectException(BankAccountEvidenceAlreadyAttached::class);

        $this->attach()->attach(BankAccountMother::DEFAULT_ID, $this->command());
    }

    #[Test]
    public function theLedgerOverrideRefusesTheAttachBeforeTheLinkIsWritten(): void
    {
        $this->documents->file(self::DOCUMENT_ID);
        $this->documents->gone = true;

        $this->assertRefusedWith(
            DocumentGone::class,
            fn () => $this->attach()->attach(BankAccountMother::DEFAULT_ID, $this->command()),
        );
    }

    private function attach(bool $withAccount = true): AttachBankAccountEvidence
    {
        return new AttachBankAccountEvidence(
            new InMemoryBankAccountRepository($withAccount ? BankAccountMother::create() : null),
            $this->evidence,
            $this->documents,
            new DescribeEvidence($this->documents),
            $this->transactions,
        );
    }

    private function command(string $documentId = self::DOCUMENT_ID): AttachBankAccountEvidenceCommand
    {
        return new AttachBankAccountEvidenceCommand($documentId, BankAccountEvidenceKind::OwnershipCertificate);
    }

    /**
     * @param class-string<Throwable> $expected
     * @param callable(): mixed       $attempt
     */
    private function assertRefusedWith(string $expected, callable $attempt): void
    {
        $thrown = null;

        try {
            $attempt();
        } catch (Throwable $throwable) {
            $thrown = $throwable;
        }

        $this->assertInstanceOf($expected, $thrown);

        $this->assertSame([], $this->evidence->links, 'nothing is written');
    }
}
