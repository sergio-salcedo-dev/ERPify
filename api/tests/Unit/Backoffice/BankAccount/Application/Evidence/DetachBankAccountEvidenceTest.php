<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Application\Evidence\DetachBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceNotFound;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Backoffice\BankAccount\Application\InMemoryBankAccountRepository;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the use case, its doubles and one exception per refusal
 */
#[CoversClass(DetachBankAccountEvidence::class)]
#[CoversClass(BankAccountEvidenceNotFound::class)]
final class DetachBankAccountEvidenceTest extends TestCase
{
    private const string OTHER_ACCOUNT = '33333333-3333-7000-8000-000000000099';

    private InMemoryBankAccountEvidenceRepository $evidence;

    private InMemoryBankAccountRepository $accounts;

    protected function setUp(): void
    {
        $this->evidence = new InMemoryBankAccountEvidenceRepository();
        $this->accounts = new InMemoryBankAccountRepository(BankAccountMother::create());
    }

    #[Test]
    public function theLinkOnThatAccountIsRemovedUnderTheAccountLock(): void
    {
        $link = $this->link(BankAccountMother::DEFAULT_ID);

        $this->detach()->detach(BankAccountMother::DEFAULT_ID, \strtoupper($link->id()));

        $this->assertSame([$link], $this->evidence->removed);
        $this->assertSame(1, $this->accounts->lockedReads);
    }

    #[Test]
    public function aLinkOfAnotherAccountIsNotFoundAndStays(): void
    {
        $link = $this->link(self::OTHER_ACCOUNT);

        try {
            $this->detach()->detach(BankAccountMother::DEFAULT_ID, $link->id());
            $this->fail('Expected BankAccountEvidenceNotFound.');
        } catch (BankAccountEvidenceNotFound $bankAccountEvidenceNotFound) {
            $this->assertSame('bank-account-evidence-not-found', $bankAccountEvidenceNotFound->type());
        }

        $this->assertSame([], $this->evidence->removed);
    }

    #[Test]
    public function anUnknownAccountIsNotFound(): void
    {
        $this->accounts = new InMemoryBankAccountRepository();

        $this->expectException(BankAccountNotFoundException::class);

        $this->detach()->detach(BankAccountMother::DEFAULT_ID, Uuid::generate());
    }

    #[Test]
    public function aMalformedLinkIdIsARequestTargetError(): void
    {
        $this->expectException(InvalidUuidException::class);

        $this->detach()->detach(BankAccountMother::DEFAULT_ID, 'not-a-uuid');
    }

    private function link(string $accountId): BankAccountEvidence
    {
        $link = BankAccountEvidence::attach(
            Uuid::generate(),
            $accountId,
            Uuid::generate(),
            BankAccountEvidenceKind::OwnershipCertificate,
        );
        $this->evidence->add($link);

        return $link;
    }

    private function detach(): DetachBankAccountEvidence
    {
        return new DetachBankAccountEvidence($this->accounts, $this->evidence, new ImmediateTransactionManager());
    }
}
