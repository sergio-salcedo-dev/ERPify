<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Kernel\Domain\ValueObject\NormalizedText;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Storage\Domain\StoredObject;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'bank')]
#[ORM\Index(name: 'idx_bank_created_at_id', columns: ['created_at', 'id'])]
#[ORM\Index(name: 'idx_bank_updated_at_id', columns: ['updated_at', 'id'])]
#[UniqueEntity(fields: ['nameNormalized'], message: 'This bank name is already in use.', errorPath: 'name')]
#[UniqueEntity(fields: ['shortName'], message: 'This code is already in use.')]
final class Bank extends AggregateRoot implements AuditedEntity
{
    /**
     * Validation limit for the bank name. It matches the default `VARCHAR(255)`
     * length of the `name` / `nameNormalized` columns, so an over-long
     * normalized name is rejected as a clean violation instead of overflowing
     * the column at INSERT time.
     */
    private const int MAX_NAME_LENGTH = 255;

    private function __construct(
        string $id,
        #[ORM\Column]
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_NAME_LENGTH)]
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
    ) {
        parent::__construct();

        $this->id = $id;
    }

    public static function create(
        string $id,
        string $name,
        string $shortName,
        ?Media $media = null,
        ?StoredObject $storedObject = null,
    ): self {
        $normalizedText = NormalizedText::from($name);

        $bank = new self(
            $id,
            $normalizedText->display,
            $normalizedText->normalized,
            NormalizedText::toAsciiUpper($shortName),
            $media,
            $storedObject ?? new StoredObject(),
        );

        $createdAt = $bank->createdAt->format(DateTimeInterface::ATOM);

        $bank->record(new BankCreatedDomainEvent(
            $id,
            new BankSnapshot(
                $bank->name,
                $bank->shortName,
                $createdAt,
                $createdAt,
                $media?->getId(),
                $media?->getContentHash(),
                $storedObject?->contentHash,
                $storedObject?->mimeType,
            ),
            null,
            $bank->createdAt,
        ));

        return $bank;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Read accessor for the accent-folded, lower-cased name. It exists so the keyset paginator can
     * read the order-by column when the list is sorted by `name`, which maps to the indexed
     * `nameNormalized`.
     */
    public function getNameNormalized(): string
    {
        return $this->nameNormalized;
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function getLogo(): ?Media
    {
        return $this->media;
    }

    public function getStoredObject(): ?StoredObject
    {
        return $this->storedObject->isEmpty() ? null : $this->storedObject;
    }

    public function auditResource(): AuditResource
    {
        return AuditResource::of('Bank', $this->id());
    }

    public function rename(string $name, string $shortName): void
    {
        $normalizedText = NormalizedText::from($name);

        $this->name = $normalizedText->display;
        $this->nameNormalized = $normalizedText->normalized;
        $this->shortName = NormalizedText::toAsciiUpper($shortName);
        $now = SystemClock::now();
        $this->updatedAt = $now;

        $this->record(new BankUpdatedDomainEvent(
            $this->id(),
            new BankSnapshot(
                $this->name,
                $this->shortName,
                $this->createdAt->format(DateTimeInterface::ATOM),
                $now->format(DateTimeInterface::ATOM),
                $this->media?->getId(),
                $this->media?->getContentHash(),
                $this->storedObject->contentHash,
                $this->storedObject->mimeType,
            ),
            null,
            $now,
        ));
    }

    public function delete(): void
    {
        $this->record(new BankDeletedDomainEvent($this->id(), null, SystemClock::now()));
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
