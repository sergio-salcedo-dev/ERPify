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
// btree indexes for the temporal range filters (story 1.7). Declared here at the Bank table
// level, never on the shared Timestamped trait, so only this entity pays for the indexes.
#[ORM\Index(name: 'idx_bank_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_bank_updated_at', columns: ['updated_at'])]
// Composite (column, id) indexes backing keyset order stability on the non-unique temporal sort
// keys (story 1.2): a tie on created_at/updated_at resolves by the id tie-break under a single
// index walk. The simple indexes above stay for the range filters. The unique name_normalized /
// short_name columns already give a total order from their single-column unique index, so they
// need no composite — see SortFieldMapIndexContractTest.
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
         * {@see \Erpify\Shared\Storage\Domain\ContentAddressableObjectKey} path (distinct from BYTEA {@see $logo}).
         */
        #[ORM\Column(name: 'stored_object_key', length: 512, nullable: true)]
        private ?string $storedObjectKey,
        #[ORM\Column(name: 'stored_object_mime_type', length: 64, nullable: true)]
        private ?string $storedObjectMimeType,
        #[ORM\Column(name: 'stored_object_byte_size', type: Types::INTEGER, nullable: true)]
        private ?int $storedObjectByteSize,
        #[ORM\Column(name: 'stored_object_content_hash', length: 64, nullable: true)]
        private ?string $storedObjectContentHash,
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
            $storedObject?->objectKey,
            $storedObject?->mimeType,
            $storedObject?->byteSize,
            $storedObject?->contentHash,
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

    public function rename(string $name, string $shortName): void
    {
        $normalizedText = NormalizedText::from($name);

        $this->name = $normalizedText->display;
        $this->nameNormalized = $normalizedText->normalized;
        $this->shortName = NormalizedText::toAsciiUpper($shortName);
        $now = new DateTimeImmutable();
        $this->updatedAt = $now;

        $this->record(new BankUpdatedDomainEvent(
            $this->id(),
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

    public function delete(): void
    {
        $this->record(new BankDeletedDomainEvent($this->id()));
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
