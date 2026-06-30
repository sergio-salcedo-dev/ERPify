<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldNormalizer;
use Override;

/**
 * Normalizer for the `bank_account.iban` column. The write side stores the IBAN canonicalized —
 * upper-case with every space removed (mirrored from {@see \Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount}'s
 * IBAN canonicalization). A plain upper-casing normalizer is not enough: it leaves the internal spaces a
 * human-grouped IBAN ("DE89 3704 0044 …") carries, so the bound `LIKE` would never match the stored
 * compact form. Applying the same rule to the search value keeps eq/in/contains matching by construction.
 */
final readonly class IbanFieldNormalizer implements FieldNormalizer
{
    #[Override]
    public function normalize(string $value): string
    {
        return \strtoupper(\str_replace(' ', '', $value));
    }
}
