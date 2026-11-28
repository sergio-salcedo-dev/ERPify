<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountEvidenceKind;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountEvidence::class)]
#[CoversClass(BankAccountEvidenceKind::class)]
final class BankAccountEvidenceTest extends TestCase
{
    #[Test]
    public function aLinkHoldsBothIdsLowerCasedTheKindAndTheInstantItWasAttached(): void
    {
        $id = Uuid::generate();
        $account = Uuid::generate();
        $document = Uuid::generate();

        $link = BankAccountEvidence::attach(
            \strtoupper($id),
            \strtoupper($account),
            \strtoupper($document),
            BankAccountEvidenceKind::OwnershipCertificate,
        );

        $this->assertSame($id, $link->id());
        $this->assertSame($account, $link->bankAccountId());
        $this->assertSame($document, $link->documentId());
        $this->assertSame(BankAccountEvidenceKind::OwnershipCertificate, $link->kind());
        $this->assertEquals(SystemClock::now(), $link->attachedAt());
    }

    #[Test]
    public function aMalformedIdIsRefused(): void
    {
        $this->expectException(InvalidUuidException::class);

        BankAccountEvidence::attach(
            Uuid::generate(),
            Uuid::generate(),
            'not-a-uuid',
            BankAccountEvidenceKind::OwnershipCertificate,
        );
    }

    #[Test]
    public function itsTrailNamesTheLinkUnderANonPersonTypeWithOneActionPerOperation(): void
    {
        $id = Uuid::generate();
        $link = BankAccountEvidence::attach(
            $id,
            Uuid::generate(),
            Uuid::generate(),
            BankAccountEvidenceKind::OwnershipCertificate,
        );

        $this->assertSame('BankAccountEvidence', $link->auditResource()->type);
        $this->assertSame($id, $link->auditResource()->id);
        $this->assertSame('BANK_ACCOUNT_EVIDENCE_CREATED', $link->auditAction(AuditWriteOperation::CREATED));
        $this->assertSame('BANK_ACCOUNT_EVIDENCE_UPDATED', $link->auditAction(AuditWriteOperation::UPDATED));
        $this->assertSame('BANK_ACCOUNT_EVIDENCE_DELETED', $link->auditAction(AuditWriteOperation::DELETED));
    }

    #[Test]
    public function theOwnershipCertificateIsFiledForTheContractUnderLegitimateInterests(): void
    {
        $kind = BankAccountEvidenceKind::OwnershipCertificate;

        $this->assertSame('ownership-certificate', $kind->value);
        $this->assertSame('contract-execution', $kind->retentionReason());
        $this->assertSame('legitimate-interests', $kind->legalBasis());
    }
}
