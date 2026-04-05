<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Media\Domain\Entity\Media;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'bank')]
class Bank extends AggregateRoot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['bank:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['bank:read'])]
    private string $name;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[Groups(['bank:read'])]
    private string $shortName;

    #[ORM\Column]
    #[Groups(['bank:read'])]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['bank:read'])]
    private DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: Media::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'logo_media_id', referencedColumnName: 'id', nullable: true)]
    private ?Media $logo = null;

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

    private function __construct()
    {
    }

    public static function create(
        Uuid $id,
        string $name,
        string $shortName,
        ?Media $logo = null,
        ?string $storedObjectKey = null,
        ?string $storedObjectMimeType = null,
        ?int $storedObjectByteSize = null,
        ?string $storedObjectContentHash = null,
    ): self {
        $bank = new self();
        $bank->id = $id;
        $bank->name = $name;
        $bank->shortName = $shortName;
        $bank->logo = $logo;
        $bank->storedObjectKey = $storedObjectKey;
        $bank->storedObjectMimeType = $storedObjectMimeType;
        $bank->storedObjectByteSize = $storedObjectByteSize;
        $bank->storedObjectContentHash = $storedObjectContentHash;
        $now = new DateTimeImmutable();
        $bank->createdAt = $now;
        $bank->updatedAt = $now;

        $createdAt = $now->format(\DateTimeInterface::ATOM);

        $bank->record(new BankCreatedDomainEvent(
            $id->toRfc4122(),
            $name,
            $shortName,
            $createdAt,
            $createdAt,
            $logo?->getId()->toRfc4122(),
            $logo?->getContentHash(),
            $storedObjectContentHash,
            $storedObjectMimeType,
        ));

        return $bank;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLogo(): ?Media
    {
        return $this->logo;
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
        $this->name = $name;
        $this->shortName = $shortName;
        $now = new DateTimeImmutable();
        $this->updatedAt = $now;

        $this->record(new BankUpdatedDomainEvent(
            $this->id->toRfc4122(),
            $name,
            $shortName,
            $this->createdAt->format(\DateTimeInterface::ATOM),
            $now->format(\DateTimeInterface::ATOM),
            $this->logo?->getId()->toRfc4122(),
            $this->logo?->getContentHash(),
            $this->storedObjectContentHash,
            $this->storedObjectMimeType,
        ));
    }
}
