<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Erpify\Backoffice\BankAccount\Domain\Iban;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldNormalizer;
use Override;

/**
 * Normalizer for the `bank_account.iban` column. A plain upper-casing normalizer is not enough: it
 * leaves the separators a human-grouped IBAN ("DE89 3704 0044 …") carries, so the bound `LIKE` would
 * never match the stored compact form.
 *
 * It calls {@see Iban::canonicalize()} rather than restating the rule: the write side canonicalizes
 * with the same function, so the search value and the stored value agree by construction instead of
 * by two comments claiming to mirror each other.
 */
final readonly class IbanFieldNormalizer implements FieldNormalizer
{
    #[Override]
    public function normalize(string $value): string
    {
        return Iban::canonicalize($value);
    }
}
