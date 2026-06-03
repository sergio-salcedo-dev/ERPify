<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Shared\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Domain\Enum\Currency;
use Erpify\Shared\Infrastructure\Validator\EnumType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'bank_account')]
#[UniqueEntity(fields: ['iban'], message: 'This IBAN is already in use.')]
class BankAccount extends AggregateRoot
{
    #[ORM\ManyToOne(targetEntity: Bank::class)]
    #[ORM\JoinColumn(name: 'bank_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull]
    private Bank $bank;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $holderName;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $alias = null;

    /**
     * Stored canonicalized: upper-case, no whitespace (see {@see canonicalizeIban()}). The unique
     * column is compared directly — no separate normalized half needed.
     *
     * IBANs are ASCII-only by specification (ISO 13616). An IBAN is composed of:
     * - 2-letter ISO country code (A–Z)
     * - 2 check digits (0–9)
     * - BBAN — alphanumeric, A–Z / 0–9
     */
    #[ORM\Column(length: 34, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Iban]
    #[Assert\Length(max: 34)]
    private string $iban;

    #[ORM\Column(length: 11, nullable: true)]
    #[Assert\Bic(ibanPropertyPath: 'iban')]
    private ?string $bic = null;

    #[ORM\Column(length: 3, enumType: Currency::class)]
    #[EnumType(Currency::class)]
    private Currency $currency;

    #[ORM\Column(type: Types::SMALLINT, enumType: BankAccountStatus::class)]
    #[EnumType(BankAccountStatus::class)]
    private BankAccountStatus $status;

    public static function create(
        string $id,
        Bank $bank,
        string $holderName,
        string $iban,
        ?string $bic = null,
        ?string $alias = null,
        Currency $currency = Currency::EUR,
        BankAccountStatus $status = BankAccountStatus::ACTIVE,
    ): self {
        $bankAccount = new self();
        $bankAccount->id = $id;
        $bankAccount->bank = $bank;
        $bankAccount->iban = self::canonicalizeIban($iban);
        $bankAccount->holderName = $holderName;
        $bankAccount->alias = $alias;
        $bankAccount->currency = $currency;
        $bankAccount->bic = null === $bic ? null : \strtoupper($bic);
        $bankAccount->status = $status;

        return $bankAccount;
    }

    public function getBank(): Bank
    {
        return $this->bank;
    }

    public function getIban(): string
    {
        return $this->iban;
    }

    public function getHolderName(): string
    {
        return $this->holderName;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function getStatus(): BankAccountStatus
    {
        return $this->status;
    }

    private static function canonicalizeIban(string $iban): string
    {
        return \strtoupper(\str_replace(' ', '', $iban));
    }
}
