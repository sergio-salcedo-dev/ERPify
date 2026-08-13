<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Command;

use Erpify\Backoffice\BankAccount\Application\Validation\BicMatchingIban;
use Erpify\Backoffice\BankAccount\Domain\Iban;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input command for {@see \Erpify\Backoffice\BankAccount\Application\BankAccountCreator}.
 *
 * HTTP maps it from the request body via #[StrictRequestPayload], which is the only caller today; the
 * #[Assert] attributes are passive validation metadata, enforced there and re-checkable via
 * Validator::ensure() by anything that later builds it with `new`. BankAccount entity invariants (IBAN
 * uniqueness via UniqueEntity) are the final guard. A new account has no `status` on the wire — it is
 * always created ACTIVE.
 *
 * `iban` and `bic` carry no width here on purpose. Whatever arrives is canonicalized before the
 * aggregate stores or measures it — separators stripped, cased up — so a width declared at this layer
 * measures a value the system never keeps, and refuses grouped spellings that #[Assert\Iban] and
 * #[Assert\Bic] both accept. Each field's width belongs to its canonical value and is asserted where
 * that value exists, on the entity; the two constraints below already refuse anything that is not an
 * IBAN or a BIC at all, at any length.
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
        public string $iban = '',
        #[BicMatchingIban]
        public ?string $bic = null,
        #[Assert\Length(max: 100, maxMessage: 'The alias must not exceed {{ limit }} characters.')]
        public ?string $alias = null,
        public Currency $currency = Currency::EUR,
    ) {
    }

    /**
     * The country half of {@see Assert\Bic} reads its paired IBAN as submitted and takes the country
     * from the first two characters, so any spelling not starting with two letters — a copy-paste that
     * leads with a separator — makes `ctype_alpha()` false and skips the comparison in silence. It
     * reads this instead: the same canonical form the aggregate compares and stores, so the pair is
     * judged at the edge in every spelling the IBAN field itself accepts.
     */
    public function canonicalIban(): string
    {
        return Iban::canonicalize($this->iban);
    }
}
