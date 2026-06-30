<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Command;

use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input command for {@see \Erpify\Backoffice\BankAccount\Application\BankAccountCreator}.
 *
 * HTTP maps it from the request body via #[MapRequestPayload]; a console command (or CQRS command bus)
 * builds it directly with `new`. The #[Assert] attributes are passive validation metadata, enforced at
 * the HTTP boundary and re-checkable via Validator::ensure() for non-HTTP callers; BankAccount entity
 * invariants (IBAN uniqueness via UniqueEntity) are the final guard. A new account has no `status` on
 * the wire — it is always created ACTIVE.
 */
final readonly class CreateBankAccountCommand
{
    public function __construct(
        #[Assert\NotBlank(message: 'The bankId field is required.')]
        #[Assert\Uuid(message: 'The bankId must be a valid UUID.')]
        public string $bankId = '',
        #[Assert\NotBlank(message: 'The holderName field is required.')]
        #[Assert\Length(max: 255, maxMessage: 'The holderName must not exceed {{ limit }} characters.')]
        public string $holderName = '',
        #[Assert\NotBlank(message: 'The iban field is required.')]
        #[Assert\Iban(message: 'This is not a valid IBAN.')]
        #[Assert\Length(max: 34, maxMessage: 'The iban must not exceed {{ limit }} characters.')]
        public string $iban = '',
        #[Assert\Length(max: 11, maxMessage: 'The bic must not exceed {{ limit }} characters.')]
        #[Assert\Bic(
            message: 'This is not a valid BIC.',
            ibanPropertyPath: 'iban',
            mode: Assert\Bic::VALIDATION_MODE_CASE_INSENSITIVE,
        )]
        public ?string $bic = null,
        #[Assert\Length(max: 100, maxMessage: 'The alias must not exceed {{ limit }} characters.')]
        public ?string $alias = null,
        public Currency $currency = Currency::EUR,
    ) {
    }
}
