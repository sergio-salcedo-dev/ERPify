<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Domain\Entity;

use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountCreatedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountDeletedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountSnapshot;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountStatusChangedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountUpdatedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotClosedException;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Shared\Privacy\Domain\PersonalData;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Shared\Validation\Infrastructure\EnumType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A rich aggregate whose collaborators are inherent to its responsibilities — persistence/validation
 * metadata, four domain events, a status lifecycle, the audit contract and per-field PII classification —
 * rather than a design smell, so its object coupling is deliberately allowed above the default threshold.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[ORM\Entity]
#[ORM\Table(name: 'bank_account')]
#[UniqueEntity(fields: ['iban'], message: 'This IBAN is already in use.')]
final class BankAccount extends AggregateRoot implements AuditedEntity
{
    private function __construct(
        string $id,
        #[ORM\Column(name: 'bank_id', type: Types::GUID)]
        #[Assert\NotBlank]
        #[Assert\Uuid]
        private string $bankId,
        #[ORM\Column(length: 255)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[PersonalData]
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
        #[PersonalData]
        private string $iban,
        #[ORM\Column(length: 11, nullable: true)]
        #[Assert\Bic(ibanPropertyPath: 'iban')]
        private ?string $bic,
        #[ORM\Column(length: 100, nullable: true)]
        #[Assert\Length(max: 100)]
        #[PersonalData]
        private ?string $alias,
        #[ORM\Column(length: 3, enumType: Currency::class)]
        #[EnumType(Currency::class)]
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

        $account = new self(
            $id,
            $bankId,
            $holderName,
            self::canonicalizeIban($iban),
            self::canonicalizeBic($bic),
            $alias,
            $currency,
            $status,
        );

        $account->record(new BankAccountCreatedDomainEvent(
            $id,
            $account->snapshot($account->createdAt->format(DateTimeInterface::ATOM)),
            null,
            $account->createdAt,
        ));

        return $account;
    }

    /**
     * Re-canonicalizes the IBAN and upper-cases the BIC exactly as {@see create()} does, so an edited
     * account stores the same canonical forms as a freshly created one. Records an updated event whose
     * PII-free snapshot reflects the new state and bumped `updatedAt`.
     */
    public function update(
        string $holderName,
        string $iban,
        ?string $bic,
        ?string $alias,
        Currency $currency,
    ): void {
        $this->holderName = $holderName;
        $this->iban = self::canonicalizeIban($iban);
        $this->bic = self::canonicalizeBic($bic);
        $this->alias = $alias;
        $this->currency = $currency;

        $now = SystemClock::now();
        $this->updatedAt = $now;

        $this->record(new BankAccountUpdatedDomainEvent(
            $this->id(),
            $this->snapshot($now->format(DateTimeInterface::ATOM)),
            null,
            $now,
        ));
    }

    /**
     * @throws BankAccountNotClosedException when the account is not in the terminal CLOSED state
     */
    public function delete(): void
    {
        if (BankAccountStatus::CLOSED !== $this->status) {
            throw BankAccountNotClosedException::withId($this->id());
        }

        $this->record(new BankAccountDeletedDomainEvent(
            $this->id(),
            $this->snapshot($this->updatedAt->format(DateTimeInterface::ATOM)),
            null,
            SystemClock::now(),
        ));
    }

    /**
     * Transitions the lifecycle status. Transitions are unrestricted (any status to any other); a
     * transition to the current status is a no-op and records nothing, so a redundant PATCH stays
     * idempotent. Records a dedicated {@see BankAccountStatusChangedDomainEvent} carrying the from/to
     * pair, distinct from a descriptive {@see update()}.
     */
    public function changeStatus(BankAccountStatus $newStatus): void
    {
        if ($newStatus === $this->status) {
            return;
        }

        $previousStatus = $this->status;
        $this->status = $newStatus;
        $this->updatedAt = SystemClock::now();

        $this->record(new BankAccountStatusChangedDomainEvent(
            $this->id(),
            $this->bankId,
            $previousStatus->value,
            $newStatus->value,
            null,
            $this->updatedAt,
        ));
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

    public function getStatus(): BankAccountStatus
    {
        return $this->status;
    }

    public function auditResource(): AuditResource
    {
        return AuditResource::of('BankAccount', $this->id());
    }

    public function auditAction(AuditWriteOperation $operation): string
    {
        return match ($operation) {
            AuditWriteOperation::CREATED => 'BANK_ACCOUNT_CREATED',
            AuditWriteOperation::UPDATED => 'BANK_ACCOUNT_UPDATED',
            AuditWriteOperation::DELETED => 'BANK_ACCOUNT_DELETED',
        };
    }

    private static function canonicalizeIban(string $iban): string
    {
        return \strtoupper(\str_replace(' ', '', $iban));
    }

    /**
     * An absent BIC is the empty value `null`: an empty string from a direct API caller (the
     * {@see Assert\Bic} constraint skips empty input) would otherwise
     * persist as `''` and surface as `bic: ""` instead of `null`.
     */
    private static function canonicalizeBic(?string $bic): ?string
    {
        if (null === $bic || '' === $bic) {
            return null;
        }

        return \strtoupper($bic);
    }

    private function snapshot(string $updatedAt): BankAccountSnapshot
    {
        return new BankAccountSnapshot(
            $this->bankId,
            $this->status->value,
            $this->createdAt->format(DateTimeInterface::ATOM),
            $updatedAt,
        );
    }
}
