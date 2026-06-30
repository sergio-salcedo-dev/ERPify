<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankAuditActionTest extends TestCase
{
    public function testItDeclaresASemanticActionForEachWriteOperation(): void
    {
        $bank = BankMother::create();

        $this->assertSame('BANK_CREATED', $bank->auditAction(AuditWriteOperation::CREATED));
        $this->assertSame('BANK_UPDATED', $bank->auditAction(AuditWriteOperation::UPDATED));
        $this->assertSame('BANK_DELETED', $bank->auditAction(AuditWriteOperation::DELETED));
    }
}
