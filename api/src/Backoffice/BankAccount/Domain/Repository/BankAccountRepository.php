<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Repository;

/**
 * Aggregate-lifecycle port for bank accounts. Only the referential-integrity
 * count is exposed here — the full BankAccount CRUD surface is out of scope.
 */
interface BankAccountRepository
{
    public function countByBankId(string $bankId): int;
}
