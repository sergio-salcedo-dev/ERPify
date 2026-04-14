<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'media')]
class Media
{
    #[ORM\Id]
    #[ORM\Column(name:'id', type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\Column(name: 'content_hash', length: 64)]
    private string $contentHash;

    #[ORM\Column(name: 'mime_type', length: 64)]
    private string $mimeType;

    #[ORM\Column(name: 'byte_size', type: Types::INTEGER)]
    private int $byteSize;

    /** @var string|resource */
    #[ORM\Column(name: 'raw_bytes', type: Types::BLOB)]
    private mixed $rawBytes;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    private function __construct()
    {
    }

    public static function create(
        Uuid $uuid,
        string $contentHash,
        string $mimeType,
        int $byteSize,
        string $rawBytes,
    ): self {
        $media = new self();
        $media->uuid = $uuid;
        $media->contentHash = $contentHash;
        $media->mimeType = $mimeType;
        $media->byteSize = $byteSize;
        $media->rawBytes = $rawBytes;

        $now = new DateTimeImmutable();
        $media->createdAt = $now;
        $media->updatedAt = $now;

        return $media;
    }

    public function getId(): Uuid
    {
        return $this->uuid;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getRawBytes(): string
    {
        if (\is_resource($this->rawBytes)) {
            $contents = stream_get_contents($this->rawBytes);
            $this->rawBytes = $contents !== false ? $contents : '';
        }

        return (string) $this->rawBytes;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isActive(): bool
    {
        return !$this->deletedAt instanceof DateTimeImmutable;
    }

    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->updatedAt = $this->deletedAt;
    }
}
