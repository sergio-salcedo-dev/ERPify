<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Command;

use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input command for {@see \Erpify\Backoffice\BankAccount\Application\BankAccountUpdater}.
 *
 * HTTP maps it from the request body via #[StrictRequestPayload]; a console command (or CQRS command bus)
 * builds it directly with `new`. Every descriptive field is editable, including the IBAN (revalidated
 * for the BIC↔IBAN pair and uniqueness, which UniqueEntity scopes to ignore the account's own row).
 * The lifecycle `status` is NOT here — it transitions through its own command/endpoint
 * ({@see \Erpify\Backoffice\BankAccount\Application\ChangeBankAccountStatusCommand}). The #[Assert]
 * attributes are passive validation metadata; BankAccount entity invariants are the final guard.
 */
final readonly class UpdateBankAccountCommand
{
    public function __construct(
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
