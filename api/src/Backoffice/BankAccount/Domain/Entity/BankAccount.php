<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Validation\Infrastructure\EnumType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'bank_account')]
#[UniqueEntity(fields: ['iban'], message: 'This IBAN is already in use.')]
final class BankAccount extends AggregateRoot
{
    /**
     * Serialization group for the account read projection (the accounts-by-bank surface). It exposes
     * the FULL canonical IBAN (upper-case, no separators): masking is a client concern, never the
     * backend's — the value is classified PII and must never be logged.
     */
    public const string GROUP_READ = 'bankaccount:read';

    private function __construct(
        string $id,
        #[ORM\Column(name: 'bank_id', type: Types::GUID)]
        #[Assert\NotBlank]
        #[Assert\Uuid]
        private string $bankId,
        #[ORM\Column(length: 255)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Groups([self::GROUP_READ])]
        private string $holderName,
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
        #[Groups([self::GROUP_READ])]
        private string $iban,
        #[ORM\Column(length: 11, nullable: true)]
        #[Assert\Bic(ibanPropertyPath: 'iban')]
        #[Groups([self::GROUP_READ])]
        private ?string $bic,
        #[ORM\Column(length: 100, nullable: true)]
        #[Assert\Length(max: 100)]
        #[Groups([self::GROUP_READ])]
        private ?string $alias,
        #[ORM\Column(length: 3, enumType: Currency::class)]
        #[EnumType(Currency::class)]
        #[Groups([self::GROUP_READ])]
        private Currency $currency,
        #[ORM\Column(type: Types::TEXT, enumType: BankAccountStatus::class)]
        #[EnumType(BankAccountStatus::class)]
        private BankAccountStatus $status,
    ) {
        parent::__construct();

        $this->id = $id;
    }

    public static function create(
        string $id,
        string $bankId,
        string $holderName,
        string $iban,
        ?string $bic = null,
        ?string $alias = null,
        Currency $currency = Currency::EUR,
        BankAccountStatus $status = BankAccountStatus::ACTIVE,
    ): self {
        Uuid::ensure($bankId);

        return new self(
            $id,
            $bankId,
            $holderName,
            self::canonicalizeIban($iban),
            null === $bic ? null : \strtoupper($bic),
            $alias,
            $currency,
            $status,
        );
    }

    public function getBankId(): string
    {
        return $this->bankId;
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

    /**
     * Serializes as `->value` (`ACTIVE`/`INACTIVE`/`CLOSED`) under the `status` key — the wire
     * contract is the enum identity, never a display label. The property carries no serializer
     * group, so this accessor is the single source of the `status` field.
     */
    #[Groups([self::GROUP_READ])]
    #[SerializedName('status')]
    public function getStatus(): BankAccountStatus
    {
        return $this->status;
    }

    private static function canonicalizeIban(string $iban): string
    {
        return \strtoupper(\str_replace(' ', '', $iban));
    }
}
