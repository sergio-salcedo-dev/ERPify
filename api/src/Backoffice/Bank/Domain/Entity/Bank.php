<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Domain\ValueObject\NormalizedText;
use Erpify\Shared\Media\Domain\Entity\Media;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'bank')]
#[UniqueEntity(fields: ['nameNormalized'], message: 'This bank name is already in use.', errorPath: 'name')]
#[UniqueEntity(fields: ['shortNameNormalized'], message: 'This short name is already in use.', errorPath: 'shortName')]
class Bank extends AggregateRoot
{
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['bank:get', 'bank:search'])]
    private string $name;

    #[ORM\Column(name: 'name_normalized', length: 255, unique: true)]
    private string $nameNormalized;

    #[ORM\Column(name: 'short_name', length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[Groups(['bank:get', 'bank:search'])]
    private string $shortName;

    #[ORM\Column(name: 'short_name_normalized', length: 50, unique: true)]
    private string $shortNameNormalized;

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
        ?string $storedObjectKey = null,
        ?string $storedObjectMimeType = null,
        ?int $storedObjectByteSize = null,
        ?string $storedObjectContentHash = null,
    ): self {
        $nameVo = NormalizedText::from($name);
        $shortNameVo = NormalizedText::from($shortName);

        $bank = new self();
        $bank->id = $id;
        $bank->name = $nameVo->display;
        $bank->nameNormalized = $nameVo->normalized;
        $bank->shortName = $shortNameVo->display;
        $bank->shortNameNormalized = $shortNameVo->normalized;
        $bank->media = $media;
        $bank->storedObjectKey = $storedObjectKey;
        $bank->storedObjectMimeType = $storedObjectMimeType;
        $bank->storedObjectByteSize = $storedObjectByteSize;
        $bank->storedObjectContentHash = $storedObjectContentHash;

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
            $storedObjectContentHash,
            $storedObjectMimeType,
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
        $nameVo = NormalizedText::from($name);
        $shortNameVo = NormalizedText::from($shortName);

        $this->name = $nameVo->display;
        $this->nameNormalized = $nameVo->normalized;
        $this->shortName = $shortNameVo->display;
        $this->shortNameNormalized = $shortNameVo->normalized;
        $now = new DateTimeImmutable();
        $this->updatedAt = $now;

        $this->record(new BankUpdatedDomainEvent(
            $this->id,
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
}
