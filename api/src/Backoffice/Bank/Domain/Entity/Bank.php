<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
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
#[UniqueEntity(fields: ['nameNormalized'], message: 'This bank name is already in use.', errorPath: 'name')]
#[UniqueEntity(fields: ['shortName'], message: 'This short name is already in use.')]
class Bank extends AggregateRoot
{
    /**
     * Validation limit for the bank name. It matches the default `VARCHAR(255)`
     * length of the `name` / `nameNormalized` columns, so an over-long
     * normalized name is rejected as a clean violation instead of overflowing
     * the column at INSERT time.
     */
    private const int MAX_NAME_LENGTH = 255;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups(['bank:get', 'bank:search'])]
    private string $name;

    #[ORM\Column(unique: true)]
    private string $nameNormalized;

    /**
     * Stored canonicalized: upper-case ASCII (no diacritics) via
     * {@see NormalizedText::toAsciiUpper()}. Comparisons / uniqueness use
     * the raw column directly — no separate normalized half needed.
     */
    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[Groups(['bank:get', 'bank:search'])]
    private string $shortName;

    #[ORM\ManyToOne(targetEntity: Media::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'logo_media_id', referencedColumnName: 'id', nullable: true)]
    private ?Media $media = null;

    /**
     * {@see \Erpify\Shared\Storage\Domain\ContentAddressableObjectKey} path (distinct from BYTEA {@see $logo}).
     */
    #[ORM\Column(name: 'stored_object_key', length: 512, nullable: true)]
    private ?string $storedObjectKey = null;

    #[ORM\Column(name: 'stored_object_mime_type', length: 64, nullable: true)]
    private ?string $storedObjectMimeType = null;

    #[ORM\Column(name: 'stored_object_byte_size', type: Types::INTEGER, nullable: true)]
    private ?int $storedObjectByteSize = null;

    #[ORM\Column(name: 'stored_object_content_hash', length: 64, nullable: true)]
    private ?string $storedObjectContentHash = null;

    public static function create(
        string $id,
        string $createEventId,
        string $name,
        string $shortName,
        ?Media $media = null,
        ?StoredObject $storedObject = null,
    ): self {
        $normalizedText = NormalizedText::from($name);

        $bank = new self();
        $bank->id = $id;
        $bank->name = $normalizedText->display;
        $bank->nameNormalized = $normalizedText->normalized;
        $bank->shortName = NormalizedText::toAsciiUpper($shortName);
        $bank->media = $media;
        $bank->storedObjectKey = $storedObject?->objectKey;
        $bank->storedObjectMimeType = $storedObject?->mimeType;
        $bank->storedObjectByteSize = $storedObject?->byteSize;
        $bank->storedObjectContentHash = $storedObject?->contentHash;

        $createdAt = $bank->createdAt->format(DateTimeInterface::ATOM);

        $bank->record(new BankCreatedDomainEvent(
            $id,
            $createEventId,
            $bank->name,
            $bank->shortName,
            $createdAt,
            $createdAt,
            $media?->getId(),
            $media?->getContentHash(),
            $storedObject?->contentHash,
            $storedObject?->mimeType,
        ));

        return $bank;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function getLogo(): ?Media
    {
        return $this->media;
    }

    public function getStoredObjectKey(): ?string
    {
        return $this->storedObjectKey;
    }

    public function getStoredObjectMimeType(): ?string
    {
        return $this->storedObjectMimeType;
    }

    public function getStoredObjectByteSize(): ?int
    {
        return $this->storedObjectByteSize;
    }

    public function getStoredObjectContentHash(): ?string
    {
        return $this->storedObjectContentHash;
    }

    public function rename(string $updateEventId, string $name, string $shortName): void
    {
        $normalizedText = NormalizedText::from($name);

        $this->name = $normalizedText->display;
        $this->nameNormalized = $normalizedText->normalized;
        $this->shortName = NormalizedText::toAsciiUpper($shortName);
        $now = new DateTimeImmutable();
        $this->updatedAt = $now;

        $this->record(new BankUpdatedDomainEvent(
            $this->id(),
            $updateEventId,
            $this->name,
            $this->shortName,
            $this->createdAt->format(DateTimeInterface::ATOM),
            $now->format(DateTimeInterface::ATOM),
            $this->media?->getId(),
            $this->media?->getContentHash(),
            $this->storedObjectContentHash,
            $this->storedObjectMimeType,
        ));
    }

    public function delete(string $deleteEventId): void
    {
        $this->record(new BankDeletedDomainEvent($this->id(), $deleteEventId));
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
