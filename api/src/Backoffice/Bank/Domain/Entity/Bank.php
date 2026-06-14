<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Domain\ValueObject\NormalizedText;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Storage\Domain\StoredObject;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'bank')]
#[ORM\Index(name: 'idx_bank_created_at_id', columns: ['created_at', 'id'])]
#[ORM\Index(name: 'idx_bank_updated_at_id', columns: ['updated_at', 'id'])]
#[UniqueEntity(fields: ['nameNormalized'], message: 'This bank name is already in use.', errorPath: 'name')]
#[UniqueEntity(fields: ['shortName'], message: 'This code is already in use.')]
final class Bank extends AggregateRoot
{
    /**
     * Validation limit for the bank name. It matches the default `VARCHAR(255)`
     * length of the `name` / `nameNormalized` columns, so an over-long
     * normalized name is rejected as a clean violation instead of overflowing
     * the column at INSERT time.
     */
    private const int MAX_NAME_LENGTH = 255;

    /** Serialization group for the single-resource detail projection (GET one, POST, PUT responses). */
    public const string GROUP_DETAIL = 'bank:detail';

    /** Serialization group for the collection projection (search / list responses). */
    public const string GROUP_LIST = 'bank:list';

    /**
     * Serialization group that opts a response into the computed logo / stored-object URLs
     * synthesized by {@see \Erpify\Backoffice\Bank\Infrastructure\Serializer\BankLogoUrlNormalizer}.
     */
    public const string GROUP_READ_URLS = 'bank:read:urls';

    /**
     * Serialization group that opts a READ response (list and single-bank detail) into the derived
     * {@see $accountCount}. Deliberately distinct from {@see GROUP_DETAIL} so the write-path responses
     * (create/update) — which also serialize with {@see GROUP_DETAIL} but never enrich the count —
     * do not emit a stale `accountCount: 0`.
     */
    public const string GROUP_ACCOUNT_COUNT = 'bank:account-count';

    /**
     * Read-projection: number of bank accounts that reference this bank. Not persisted — it is a
     * derived count assembled at read time (list and detail) by the application layer through the
     * BankAccount read port {@see \Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCounter},
     * never by Bank itself. Defaults to 0 so the field is always a non-null integer, even before
     * enrichment.
     */
    #[Groups([self::GROUP_ACCOUNT_COUNT])]
    private int $accountCount = 0;

    private function __construct(
        string $id,
        #[ORM\Column]
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_NAME_LENGTH)]
        #[Groups([self::GROUP_DETAIL, self::GROUP_LIST])]
        private string $name,
        #[ORM\Column(unique: true)]
        private string $nameNormalized,
        /**
         * Stored canonicalized: upper-case ASCII (no diacritics) via
         * {@see NormalizedText::toAsciiUpper()}. Comparisons / uniqueness use
         * the raw column directly — no separate normalized half needed.
         */
        #[ORM\Column(length: 50, unique: true)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        #[Groups([self::GROUP_DETAIL, self::GROUP_LIST])]
        private string $shortName,
        #[ORM\ManyToOne(targetEntity: Media::class, cascade: ['persist'])]
        #[ORM\JoinColumn(name: 'logo_media_id', referencedColumnName: 'id', nullable: true)]
        private ?Media $media,
        /**
         * Object-storage handle (key + metadata), distinct from the BYTEA-backed {@see $media}.
         * Mapped inline under the `stored_object_` prefix; an absent image is the empty value object.
         */
        #[ORM\Embedded(class: StoredObject::class, columnPrefix: 'stored_object_')]
        private StoredObject $storedObject,
        ?DateTimeImmutable $now = null,
    ) {
        parent::__construct($now);

        $this->id = $id;
    }

    public static function create(
        string $id,
        string $name,
        string $shortName,
        ?Media $media = null,
        ?StoredObject $storedObject = null,
        ?DateTimeImmutable $now = null,
    ): self {
        $normalizedText = NormalizedText::from($name);

        $bank = new self(
            $id,
            $normalizedText->display,
            $normalizedText->normalized,
            NormalizedText::toAsciiUpper($shortName),
            $media,
            $storedObject ?? new StoredObject(),
            $now,
        );

        $createdAt = $bank->createdAt->format(DateTimeInterface::ATOM);

        $bank->record(new BankCreatedDomainEvent(
            $id,
            $bank->name,
            $bank->shortName,
            $createdAt,
            $createdAt,
            $media?->getId(),
            $media?->getContentHash(),
            $storedObject?->contentHash,
            $storedObject?->mimeType,
            $bank->createdAt,
        ));

        return $bank;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Read accessor for the accent-folded, lower-cased name. Carries no serializer group, so it
     * stays out of the HTTP payload; it exists so the keyset paginator can read the order-by
     * column when the list is sorted by `name` (which maps to the indexed `nameNormalized`).
     */
    public function getNameNormalized(): string
    {
        return $this->nameNormalized;
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function getAccountCount(): int
    {
        return $this->accountCount;
    }

    /**
     * Enrich this read-projection with the number of accounts referencing the bank, computed by the
     * application layer via the BankAccount read port. Read-only concern: it never participates in
     * the aggregate's write invariants or domain events.
     */
    public function assignAccountCount(int $accountCount): void
    {
        $this->accountCount = $accountCount;
    }

    public function getLogo(): ?Media
    {
        return $this->media;
    }

    public function getStoredObject(): ?StoredObject
    {
        return $this->storedObject->isEmpty() ? null : $this->storedObject;
    }

    public function rename(string $name, string $shortName, ?DateTimeImmutable $now = null): void
    {
        $normalizedText = NormalizedText::from($name);

        $this->name = $normalizedText->display;
        $this->nameNormalized = $normalizedText->normalized;
        $this->shortName = NormalizedText::toAsciiUpper($shortName);
        $now ??= new DateTimeImmutable();
        $this->updatedAt = $now;

        $this->record(new BankUpdatedDomainEvent(
            $this->id(),
            $this->name,
            $this->shortName,
            $this->createdAt->format(DateTimeInterface::ATOM),
            $now->format(DateTimeInterface::ATOM),
            $this->media?->getId(),
            $this->media?->getContentHash(),
            $this->storedObject->contentHash,
            $this->storedObject->mimeType,
            $now,
        ));
    }

    public function delete(?DateTimeImmutable $now = null): void
    {
        $this->record(new BankDeletedDomainEvent($this->id(), $now));
    }

    /**
     * The unique `nameNormalized` column stores the accent-folded, lower-cased
     * form of the name (e.g. "Æ" -> "ae", "ß" -> "ss"), which can be longer than
     * what the user typed. The {@see Assert\Length} on `$name` only bounds the raw
     * value, so a name within the limit can still fold to an over-long normalized
     * twin and overflow the column. Reject it here as a clean violation on `name`
     * rather than letting the INSERT raise a database "value too long" 500. Skip
     * when the raw name already breached the limit so a single over-long name
     * yields one violation, not two.
     */
    #[Assert\Callback]
    public function validateNormalizedNameLength(ExecutionContextInterface $context): void
    {
        if (\mb_strlen($this->name) > self::MAX_NAME_LENGTH) {
            return;
        }

        if (\mb_strlen($this->nameNormalized) <= self::MAX_NAME_LENGTH) {
            return;
        }

        $context->buildViolation('The name must not exceed {{ limit }} characters.')
            ->setParameter('{{ limit }}', (string) self::MAX_NAME_LENGTH)
            ->atPath('name')
            ->addViolation()
        ;
    }
}
