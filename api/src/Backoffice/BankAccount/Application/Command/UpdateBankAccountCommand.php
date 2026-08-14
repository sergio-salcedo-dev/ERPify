<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Command;

use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input command for {@see \Erpify\Backoffice\BankAccount\Application\BankAccountUpdater}.
 *
 * HTTP maps it from the request body via #[StrictRequestPayload], which is the only caller today. Every
 * descriptive field is editable, including the IBAN (revalidated for the BIC↔IBAN pair and uniqueness,
 * which UniqueEntity scopes to ignore the account's own row). The lifecycle `status` is NOT here — it
 * transitions through its own command/endpoint
 * ({@see \Erpify\Backoffice\BankAccount\Application\ChangeBankAccountStatusCommand}). The #[Assert]
 * attributes are passive validation metadata; BankAccount entity invariants are the final guard.
 *
 * `iban` and `bic` carry no width here on purpose. Whatever arrives is canonicalized before the
 * aggregate stores or measures it — separators stripped, cased up — so a width declared at this layer
 * measures a value the system never keeps, and refuses grouped spellings that #[Assert\Iban] and
 * #[Assert\Bic] both accept. Each field's width belongs to its canonical value and is asserted where
 * that value exists, on the entity; the two constraints below already refuse anything that is not an
 * IBAN or a BIC at all, at any length.
 */
final readonly class UpdateBankAccountCommand
{
    public function __construct(
        #[Assert\NotBlank(message: 'The holderName field is required.')]
        #[Assert\Length(max: 255, maxMessage: 'The holderName must not exceed {{ limit }} characters.')]
        public string $holderName = '',
        #[Assert\NotBlank(message: 'The iban field is required.')]
        #[Assert\Iban(message: 'This is not a valid IBAN.')]
        public string $iban = '',
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
