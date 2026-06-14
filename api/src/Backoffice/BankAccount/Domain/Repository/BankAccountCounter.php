<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Repository;

/**
 * Read-side (query) port: resolves how many bank accounts reference each of a batch of banks in a
 * single aggregate query, so a banks-list page can carry per-row counts without an N+1. Segregated
 * from the write-side lifecycle port {@see BankAccountRepository} — this surface is read-only and is
 * the only BankAccount capability wired into the banks read-path.
 */
interface BankAccountCounter
{
    /**
     * @param list<string> $bankIds bank UUIDs of the page (or single bank) being assembled
     *
     * @return array<string, int> bankId => account count; banks with no accounts are absent from the map
     */
    public function countsByBankIds(array $bankIds): array;
}
