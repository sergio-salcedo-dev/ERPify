<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Event\Mother;

use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountSnapshot;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;

final class BankAccountSnapshotMother
{
    public static function create(
        string $bankId = BankAccountMother::DEFAULT_BANK_ID,
        string $status = 'ACTIVE',
        string $createdAt = '2026-06-06T00:00:00+00:00',
        string $updatedAt = '2026-06-06T00:00:00+00:00',
    ): BankAccountSnapshot {
        return new BankAccountSnapshot($bankId, $status, $createdAt, $updatedAt);
    }
}
