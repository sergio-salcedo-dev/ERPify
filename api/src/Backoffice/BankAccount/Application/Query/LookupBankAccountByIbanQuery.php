<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Query;

use Erpify\Backoffice\BankAccount\Domain\Iban;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST-body request DTO for the IBAN exact-lookup endpoint — the non-logging counterpart to the
 * generic `filters[]` vocabulary's GET query string, which never carries a field this sensitive.
 */
final readonly class LookupBankAccountByIbanQuery
{
    public function __construct(
        #[Assert\NotBlank(message: 'The iban field is required.')]
        #[Assert\Iban(message: 'This is not a valid IBAN.')]
        public string $iban = '',
    ) {
    }

    public function canonicalIban(): string
    {
        return Iban::canonicalize($this->iban);
    }
}
