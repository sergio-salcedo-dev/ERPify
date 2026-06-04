<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'media')]
class Media extends AggregateRoot
{
    /**
     * Lifecycle state, not a construction input: always null on creation,
     * set only via {@see softDelete()}.
     */
    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    private function __construct(
        string $id,
        #[ORM\Column(name: 'content_hash', length: 64)]
        private string $contentHash,
        #[ORM\Column(name: 'mime_type', length: 64)]
        private string $mimeType,
        #[ORM\Column(name: 'byte_size', type: Types::INTEGER)]
        private int $byteSize,
        /** @var resource|string */
        #[ORM\Column(name: 'raw_bytes', type: Types::BLOB)]
        private mixed $rawBytes,
    ) {
        parent::__construct();

        $this->id = $id;
    }

    public static function create(
        string $id,
        string $contentHash,
        string $mimeType,
        int $byteSize,
        string $rawBytes,
    ): self {
        return new self($id, $contentHash, $mimeType, $byteSize, $rawBytes);
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
            $contents = \stream_get_contents($this->rawBytes);
            $this->rawBytes = false !== $contents ? $contents : '';
        }

        return $this->rawBytes;
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
