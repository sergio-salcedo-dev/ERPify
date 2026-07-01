<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccount::class)]
final class BankAccountAuditTest extends TestCase
{
    #[Test]
    public function itMapsEachWriteOperationToASemanticAction(): void
    {
        $account = $this->account(Uuid::generate());

        $this->assertSame('BANK_ACCOUNT_CREATED', $account->auditAction(AuditWriteOperation::CREATED));
        $this->assertSame('BANK_ACCOUNT_UPDATED', $account->auditAction(AuditWriteOperation::UPDATED));
        $this->assertSame('BANK_ACCOUNT_DELETED', $account->auditAction(AuditWriteOperation::DELETED));
    }

    #[Test]
    public function itsAuditResourceIsTheBankAccountById(): void
    {
        $id = Uuid::generate();

        $resource = $this->account($id)->auditResource();

        $this->assertSame('BankAccount', $resource->type);
        $this->assertSame($id, $resource->id);
    }

    private function account(string $id): BankAccount
    {
        return BankAccount::create($id, Uuid::generate(), 'Holder', 'ES9121000418450200051332');
    }
}
